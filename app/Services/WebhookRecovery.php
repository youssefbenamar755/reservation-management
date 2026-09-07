<?php

namespace App\Services;

use App\Jobs\ProcessFluentWebhookEvent;
use App\Jobs\ProcessWooWebhookEvent;
use App\Jobs\RetryWebhookEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WebhookRetryAttempt;
use App\Support\FluentWebhookPayload;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class WebhookRecovery
{
    public const QUEUED = 'Retry queued.';

    public const RUNNING = 'Retry is processing.';

    public const SUCCEEDED = 'Webhook processed successfully.';

    public const FAILED = 'Retry failed. Review the delivery details before trying again.';

    public const EXISTING = 'Submission already exists; existing data was kept unchanged.';

    public const SKIPPED = 'Retry was skipped because the delivery is no longer eligible.';

    public const INACTIVE = 'Retry was skipped because the website is not active.';

    /** @return array{can_retry: bool, retry_reason: ?string} */
    public function eligibility(WebhookEvent $event): array
    {
        $reason = $this->ineligibleReason($event, 'failed');

        return ['can_retry' => $reason === null, 'retry_reason' => $reason];
    }

    public function retry(WebhookEvent $event, User $actor): WebhookRetryAttempt
    {
        $attempt = DB::transaction(function () use ($event, $actor) {
            // Every claimant locks this row before checking status or creating history.
            $current = WebhookEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $reason = $this->ineligibleReason($current, 'failed');
            if ($reason !== null) {
                throw ValidationException::withMessages(['retry' => $reason]);
            }
            if (WebhookRetryAttempt::where('webhook_event_id', $current->id)
                ->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['retry' => 'A retry is already in progress.']);
            }
            $attempt = WebhookRetryAttempt::create([
                'webhook_event_id' => $current->id,
                'user_id' => $actor->id,
                'status' => 'queued',
                'requested_at' => now(),
                'result_message' => self::QUEUED,
            ]);
            $current->update(['status' => 'queued', 'processed_at' => null]);

            return $attempt;
        }, 3);

        // Dispatch only after the claim transaction commits. Sync queues complete here;
        // async queues return the durable queued attempt immediately.
        try {
            Bus::dispatch(new RetryWebhookEvent($attempt->id));
        } catch (Throwable $exception) {
            $this->fail($attempt->id, $exception);
        }

        return $attempt->fresh();
    }

    public function process(int $attemptId): void
    {
        $eventId = WebhookRetryAttempt::whereKey($attemptId)->value('webhook_event_id');
        if ($eventId === null) {
            return;
        }
        $event = DB::transaction(function () use ($eventId, $attemptId) {
            $event = WebhookEvent::whereKey($eventId)->lockForUpdate()->first();
            $attempt = WebhookRetryAttempt::whereKey($attemptId)->lockForUpdate()->first();
            if (! $event || ! $attempt || $attempt->status !== 'queued') {
                return null;
            }
            if ($this->ineligibleReason($event, 'queued') !== null) {
                $attempt->update([
                    'status' => 'skipped', 'finished_at' => now(),
                    'result_message' => $event->website?->status !== 'active' ? self::INACTIVE : self::SKIPPED,
                ]);
                if ($event->status === 'queued') {
                    $event->update(['status' => 'failed']);
                }

                return null;
            }
            $attempt->update(['status' => 'running', 'started_at' => now(), 'result_message' => self::RUNNING]);

            return $event;
        }, 3);
        if (! $event) {
            return;
        }

        try {
            $skipped = false;
            if ($event->source === 'woocommerce') {
                app()->call([new ProcessWooWebhookEvent($event->id), 'handle']);
            } else {
                $processor = new ProcessFluentWebhookEvent($event->id, preserveExisting: true);
                $processor->handle();
                $skipped = $processor->skippedExisting;
            }
            DB::transaction(function () use ($eventId, $attemptId, $skipped) {
                $event = WebhookEvent::whereKey($eventId)->lockForUpdate()->first();
                $attempt = WebhookRetryAttempt::whereKey($attemptId)->lockForUpdate()->first();
                if (! $event || ! $attempt || $attempt->status !== 'running') {
                    return;
                }
                $successful = $event->status === 'processed';
                $attempt->update([
                    'status' => $successful ? ($skipped ? 'skipped' : 'succeeded') : 'failed',
                    'finished_at' => now(),
                    'result_message' => $successful ? ($skipped ? self::EXISTING : self::SUCCEEDED) : self::FAILED,
                ]);
                if (! $successful && $event->status === 'queued') {
                    $event->update(['status' => 'failed']);
                }
            }, 3);
        } catch (Throwable $exception) {
            $this->fail($attemptId, $exception);
        }
    }

    public function fail(int $attemptId, Throwable $exception): void
    {
        Log::error('Webhook retry failed', ['attempt_id' => $attemptId, 'error' => $exception->getMessage()]);
        $eventId = WebhookRetryAttempt::whereKey($attemptId)->value('webhook_event_id');
        if ($eventId === null) {
            return;
        }
        DB::transaction(function () use ($attemptId, $eventId, $exception) {
            $event = WebhookEvent::whereKey($eventId)->lockForUpdate()->first();
            $attempt = WebhookRetryAttempt::whereKey($attemptId)->lockForUpdate()->first();
            if (! $event || ! $attempt || ! in_array($attempt->status, ['queued', 'running'], true)) {
                return;
            }
            // Processing may have committed before the worker stopped during
            // broadcasting or final history bookkeeping. Keep that durable result.
            if ($event->status === 'processed') {
                $ran = $attempt->status === 'running';
                $attempt->update([
                    'status' => $ran ? 'succeeded' : 'skipped', 'finished_at' => now(),
                    'result_message' => $ran ? self::SUCCEEDED : self::SKIPPED,
                ]);

                return;
            }
            $attempt->update(['status' => 'failed', 'finished_at' => now(), 'result_message' => self::FAILED]);
            if ($event->status === 'queued') {
                $event->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            }
        }, 3);
    }

    private function ineligibleReason(WebhookEvent $event, string $requiredStatus): ?string
    {
        if ($event->status !== $requiredStatus) {
            return in_array($event->status, ['queued'], true)
                ? 'This delivery is waiting to process. Refresh to check its status.' : 'Only failed deliveries can be retried.';
        }
        if (! in_array($event->signature_valid, [true, 1, '1'], true)) {
            return 'Only deliveries with a verified signature can be retried.';
        }
        if ($event->website?->status !== 'active') {
            return 'The website must be active before retrying a delivery.';
        }
        if (! in_array($event->source, ['woocommerce', 'fluentforms'], true) ||
            ! in_array($event->topic, $event->source === 'woocommerce' ? ['order.created', 'order.updated'] : ['form.submitted'], true)) {
            return 'This delivery type does not support retry.';
        }
        if (! is_array($event->payload)) {
            return 'The saved delivery is missing valid required identifiers.';
        }
        if ($event->source === 'woocommerce') {
            $id = $this->positiveId($event->payload['id'] ?? $event->external_id);
        } else {
            $payload = FluentWebhookPayload::normalize($event->payload);
            $id = $this->positiveId(data_get($payload, '__submission.id'));
            if ($this->positiveId(data_get($payload, '__submission.form_id')) === null) {
                return 'The saved delivery is missing valid required identifiers.';
            }
        }
        if ($id === null || ($event->external_id !== null && $this->positiveId($event->external_id) !== $id)) {
            return 'The saved delivery is missing valid required identifiers.';
        }

        return null;
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
