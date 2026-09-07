<?php

use App\Events\NewWcOrderReceived;
use App\Jobs\ProcessFluentWebhookEvent;
use App\Jobs\RetryWebhookEvent;
use App\Jobs\SyncFormSchema;
use App\Models\FfSubmission;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use App\Models\WebhookRetryAttempt;
use App\Models\Website;
use App\Notifications\NewFormSubmissionNotification;
use App\Services\FluentFormSchemaService;
use App\Services\WebhookRecovery;
use App\Services\WooCommerceOrderStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['queue.default' => 'sync', 'broadcasting.default' => 'null']);
    Http::preventStrayRequests();
    Http::fake();
    Event::fake([NewWcOrderReceived::class]);
    $this->actor = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->actor->id, 'name' => 'Recovery fixture', 'slug' => 'recovery-fixture',
        'base_url' => 'https://recovery.example.test', 'status' => 'active',
    ]);
    $this->payload = [
        'id' => 42, 'status' => 'completed', 'currency' => 'EUR', 'total' => '75.00',
        'date_created_gmt' => '2026-09-01T09:00:00', 'date_modified_gmt' => '2026-09-07T09:00:00',
        'billing' => ['email' => 'synthetic@example.test', 'first_name' => 'Fixture'],
    ];
    $this->event = WebhookEvent::create([
        'website_id' => $this->website->id, 'source' => 'woocommerce', 'topic' => 'order.created',
        'external_id' => '42', 'signature_valid' => true, 'status' => 'failed',
        'received_at' => now()->subHour(), 'payload' => $this->payload, 'error_message' => 'Original database failure',
    ]);
    $this->recovery = app(WebhookRecovery::class);
});

function makeFluentRetryEvent(WebhookEvent $event): WebhookEvent
{
    $event->update([
        'source' => 'fluentforms', 'topic' => 'form.submitted', 'external_id' => '91',
        'payload' => [[
            '__submission' => ['id' => 91, 'form_id' => 7, 'created_at' => '2026-09-01 09:00:00', 'payment_status' => 'pending', 'payment_total' => 1500],
            'email' => 'older@example.test', 'names' => 'Old response',
        ]],
    ]);

    return $event->fresh();
}

function richRetrySubmission(Website $website): array
{
    return [
        'website_id' => $website->id, 'form_id' => 7, 'entry_id' => 91,
        'email' => 'current@example.test', 'payment_status' => 'paid', 'amount' => '90.00',
        'created_at_wp' => '2026-09-06 09:00:00', 'payload' => ['response' => ['names' => 'Current passenger', 'nested' => ['paid' => true]]],
        'pnr' => 'ABC123', 'pnr_generated_at' => '2026-09-06 10:00:00', 'pnr_source' => 'manual',
        'pnr_pdf_path' => 'tickets/preserved.pdf', 'amadeus_command_block' => 'PRESERVE THIS',
        'amadeus_generated_at' => '2026-09-06 10:00:00',
    ];
}

test('retry eligibility rejects unsafe states, topics, signatures, and malformed or contradictory identifiers', function () {
    expect($this->recovery->eligibility($this->event))->toBe(['can_retry' => true, 'retry_reason' => null]);
    foreach ([
        ['status' => 'processed'], ['status' => 'queued'], ['signature_valid' => false],
        ['source' => 'unknown'], ['topic' => 'order.deleted'], ['payload' => ['id' => 0]],
        ['payload' => ['id' => true]], ['payload' => ['id' => 43]], ['external_id' => 'invalid'],
    ] as $attributes) {
        $candidate = clone $this->event;
        $candidate->forceFill($attributes);
        $result = $this->recovery->eligibility($candidate);
        expect($result['can_retry'])->toBeFalse()->and($result['retry_reason'])->toBeString();
    }
    $this->website->update(['status' => 'paused']);
    expect($this->recovery->eligibility($this->event->fresh())['can_retry'])->toBeFalse();
    $this->website->update(['status' => 'active']);
    $fluent = makeFluentRetryEvent($this->event);
    expect($this->recovery->eligibility($fluent)['can_retry'])->toBeTrue();
    foreach ([['__submission' => ['id' => 91]], ['__submission' => ['id' => 91, 'form_id' => -1]], ['__submission' => ['id' => 92, 'form_id' => 7]]] as $payload) {
        $fluent->payload = $payload;
        expect($this->recovery->eligibility($fluent)['can_retry'])->toBeFalse();
    }
});

test('a rejected retry leaves the failed event and history untouched', function () {
    Queue::fake();
    $this->event->update(['signature_valid' => false]);
    expect(fn () => $this->recovery->retry($this->event, $this->actor))->toThrow(ValidationException::class);
    expect($this->event->fresh()->status)->toBe('failed');
    $this->assertDatabaseCount('webhook_retry_attempts', 0);
    Queue::assertNothingPushed();
});

test('queued retries claim atomically and reject a stale second click without creating another attempt or job', function () {
    Queue::fake([RetryWebhookEvent::class]);
    $attempt = $this->recovery->retry($this->event, $this->actor);
    expect($attempt->status)->toBe('queued')->and($attempt->user_id)->toBe($this->actor->id)
        ->and($attempt->requested_at)->not->toBeNull()->and($attempt->started_at)->toBeNull()
        ->and($attempt->result_message)->toBe(WebhookRecovery::QUEUED)
        ->and($this->event->fresh()->status)->toBe('queued');
    expect(fn () => $this->recovery->retry($this->event, $this->actor))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('webhook_retry_attempts', 1);
    Queue::assertPushed(RetryWebhookEvent::class, 1);
    Queue::assertPushed(RetryWebhookEvent::class, fn ($job) => $job->attemptId === $attempt->id);
});

test('a competing claim during attempt creation cannot create a second active retry', function () {
    Queue::fake([RetryWebhookEvent::class]);
    $rejected = false;
    WebhookRetryAttempt::created(function () use (&$rejected) {
        try {
            $this->recovery->retry($this->event, $this->actor);
        } catch (ValidationException) {
            $rejected = true;
        }
    });
    $this->recovery->retry($this->event, $this->actor);
    expect($rejected)->toBeTrue();
    $this->assertDatabaseCount('webhook_retry_attempts', 1);
    Queue::assertPushed(RetryWebhookEvent::class, 1);
});

test('sync retry returns a successful attempt with one order and one inbox notification', function () {
    $attempt = $this->recovery->retry($this->event, $this->actor);
    expect($attempt->status)->toBe('succeeded')->and($attempt->started_at)->not->toBeNull()
        ->and($attempt->finished_at)->not->toBeNull()->and($attempt->result_message)->toBe(WebhookRecovery::SUCCEEDED)
        ->and($this->event->fresh()->status)->toBe('processed')->and($this->event->fresh()->error_message)->toBeNull();
    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 1);
    Event::assertDispatchedTimes(NewWcOrderReceived::class, 1);
    expect(fn () => $this->recovery->retry($this->event, $this->actor))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

test('a queued retry job is idempotent when a queue redelivers the same attempt', function () {
    Queue::fake([RetryWebhookEvent::class]);
    $attempt = $this->recovery->retry($this->event, $this->actor);
    $job = new RetryWebhookEvent($attempt->id);
    $job->handle($this->recovery);
    $finishedAt = $attempt->fresh()->finished_at;
    $this->travel(1)->minutes();
    $job->handle($this->recovery);
    $job->failed(new RuntimeException('Late duplicate worker failure'));
    expect($attempt->fresh()->status)->toBe('succeeded')->and($attempt->fresh()->finished_at->equalTo($finishedAt))->toBeTrue()
        ->and($this->event->fresh()->status)->toBe('processed');
    $this->assertDatabaseCount('wc_orders', 1);
    $this->assertDatabaseCount('notifications', 1);
    Event::assertDispatchedTimes(NewWcOrderReceived::class, 1);
});

test('an older Woo retry cannot replace newer order data or duplicate notifications', function () {
    app(WooCommerceOrderStore::class)->store($this->website->id, array_replace($this->payload, [
        'status' => 'refunded', 'date_modified_gmt' => '2026-09-07T11:00:00',
    ]));
    $original = WcOrder::sole()->getRawOriginal();
    $attempt = $this->recovery->retry($this->event, $this->actor);
    expect($attempt->status)->toBe('succeeded')->and(WcOrder::sole()->getRawOriginal())->toBe($original);
    $this->assertDatabaseCount('notifications', 0);
    Event::assertNotDispatched(NewWcOrderReceived::class);
});

test('Fluent retries skip existing submission data including payments, payload, PNR, and generated documents', function () {
    Notification::fake();
    Bus::fake([SyncFormSchema::class]);
    $event = makeFluentRetryEvent($this->event);
    $submission = FfSubmission::create(richRetrySubmission($this->website));
    $before = $submission->fresh()->getRawOriginal();
    $attempt = $this->recovery->retry($event, $this->actor);
    expect($attempt->status)->toBe('skipped')->and($attempt->result_message)->toBe(WebhookRecovery::EXISTING)
        ->and($submission->fresh()->getRawOriginal())->toBe($before)->and($event->fresh()->status)->toBe('processed');
    Notification::assertNothingSent();
    Bus::assertNotDispatched(SyncFormSchema::class);
    Http::assertNothingSent();
});

test('a submission inserted after the Fluent precheck wins unchanged and receives no duplicate notification', function () {
    Notification::fake();
    $event = makeFluentRetryEvent($this->event);
    $inserted = false;
    DB::listen(function ($query) use (&$inserted) {
        // Insert after the original SELECT executed, while its empty result is
        // returning to the processor, outside firstOrCreate's insert savepoint.
        if (! $inserted && str_starts_with($query->sql, 'select * from "ff_submissions"')) {
            $inserted = true;
            FfSubmission::create(richRetrySubmission($this->website));
        }
    });
    $attempt = $this->recovery->retry($event, $this->actor);
    $submission = FfSubmission::sole();
    expect($attempt->status)->toBe('skipped')->and($submission->email)->toBe('current@example.test')
        ->and($submission->payment_status)->toBe('paid')->and($submission->amount)->toBe('90.00')
        ->and($submission->pnr)->toBe('ABC123')->and($submission->payload['response']['names'])->toBe('Current passenger');
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('a missing Fluent submission is recovered once without schema network work', function () {
    Notification::fake();
    Bus::fake([SyncFormSchema::class]);
    $admin = User::factory()->create(['is_admin' => true]);
    $event = makeFluentRetryEvent($this->event);
    $attempt = $this->recovery->retry($event, $this->actor);
    expect($attempt->status)->toBe('succeeded')->and(FfSubmission::sole()->email)->toBe('older@example.test')
        ->and(FfSubmission::sole()->amount)->toBe('15.00');
    Notification::assertSentToTimes($admin, NewFormSubmissionNotification::class, 1);
    (new RetryWebhookEvent($attempt->id))->handle($this->recovery);
    Notification::assertSentToTimes($admin, NewFormSubmissionNotification::class, 1);
    Bus::assertNotDispatched(SyncFormSchema::class);
    Http::assertNothingSent();
});

test('Fluent jobs serialized before recovery was added retain their original processing mode', function () {
    Bus::fake([SyncFormSchema::class]);
    $event = makeFluentRetryEvent($this->event);
    $job = (new ReflectionClass(ProcessFluentWebhookEvent::class))->newInstanceWithoutConstructor();
    $job->__unserialize(['webhookEventId' => $event->id]);
    expect($job->preserveExisting)->toBeFalse();
    $job->handle();
    expect($event->fresh()->status)->toBe('processed')->and(FfSubmission::sole()->email)->toBe('older@example.test');
    Bus::assertDispatched(SyncFormSchema::class);
    Http::assertNothingSent();
});

test('normal Fluent ingestion checks for duplicate notifications after inline schema work', function () {
    Notification::fake();
    User::factory()->create(['is_admin' => true]);
    $event = makeFluentRetryEvent($this->event);
    $this->mock(FluentFormSchemaService::class)->shouldReceive('syncFormSchema')->once()->andReturnUsing(function () {
        FfSubmission::create(richRetrySubmission($this->website));

        return null;
    });
    (new ProcessFluentWebhookEvent($event->id))->handle();
    expect($event->fresh()->status)->toBe('processed')->and(FfSubmission::sole()->email)->toBe('older@example.test');
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('processor failure is tracked safely and a later manual retry creates a separate history entry', function () {
    $raw = 'SQLSTATE secret-token fixture failure';
    $this->mock(WooCommerceOrderStore::class)->shouldReceive('store')->once()->andThrow(new RuntimeException($raw));
    $attempt = $this->recovery->retry($this->event, $this->actor);
    expect($attempt->status)->toBe('failed')->and($attempt->finished_at)->not->toBeNull()
        ->and($attempt->result_message)->toBe(WebhookRecovery::FAILED)->and($attempt->result_message)->not->toContain('secret-token')
        ->and($this->event->fresh()->status)->toBe('failed')->and($this->event->fresh()->error_message)->toBe($raw);
    app()->forgetInstance(WooCommerceOrderStore::class);
    $recovered = $this->recovery->retry($this->event, $this->actor);
    expect($recovered->id)->not->toBe($attempt->id)->and($recovered->status)->toBe('succeeded');
    $this->assertDatabaseCount('webhook_retry_attempts', 2);
});

test('a processor validation failure that returns normally is still a failed retry', function () {
    $this->mock(WooCommerceOrderStore::class)->shouldReceive('store')->once()->andThrow(new InvalidArgumentException('Internal invalid fixture data'));
    $attempt = $this->recovery->retry($this->event, $this->actor);
    expect($attempt->status)->toBe('failed')->and($attempt->result_message)->toBe(WebhookRecovery::FAILED)
        ->and($this->event->fresh()->status)->toBe('failed');
});

test('Woo recovery accepts its stored external ID when the payload ID was absent', function () {
    $payload = $this->payload;
    unset($payload['id']);
    $this->event->update(['payload' => $payload]);
    expect($this->recovery->eligibility($this->event)['can_retry'])->toBeTrue();
    $attempt = $this->recovery->retry($this->event, $this->actor);
    expect($attempt->status)->toBe('succeeded')->and(WcOrder::sole()->wp_order_id)->toBe(42);
});

test('queue dispatch failure restores retry eligibility and records only a fixed public message', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis secret fixture connection failure'));
    $attempt = $this->recovery->retry($this->event, $this->actor);
    expect($attempt->status)->toBe('failed')->and($attempt->started_at)->toBeNull()
        ->and($attempt->finished_at)->not->toBeNull()->and($attempt->result_message)->toBe(WebhookRecovery::FAILED)
        ->and($this->recovery->eligibility($this->event->fresh())['can_retry'])->toBeTrue();
});

test('a worker failure after processing committed records success without downgrading the delivery', function () {
    Queue::fake([RetryWebhookEvent::class]);
    $attempt = $this->recovery->retry($this->event, $this->actor);
    $attempt->update(['status' => 'running', 'started_at' => now()]);
    $this->event->update(['status' => 'processed', 'processed_at' => now(), 'error_message' => null]);
    (new RetryWebhookEvent($attempt->id))->failed(new RuntimeException('Worker stopped after commit'));
    expect($attempt->fresh()->status)->toBe('succeeded')->and($attempt->fresh()->finished_at)->not->toBeNull()
        ->and($attempt->fresh()->result_message)->toBe(WebhookRecovery::SUCCEEDED)
        ->and($this->event->fresh()->status)->toBe('processed')->and($this->event->fresh()->error_message)->toBeNull();
});

test('queued retry rechecks website and event state before processing', function (string $change) {
    Queue::fake([RetryWebhookEvent::class]);
    $attempt = $this->recovery->retry($this->event, $this->actor);
    match ($change) {
        'paused' => $this->website->update(['status' => 'paused']),
        'processed' => $this->event->update(['status' => 'processed', 'processed_at' => now()]),
        'unsigned' => $this->event->update(['signature_valid' => false]),
    };
    (new RetryWebhookEvent($attempt->id))->handle($this->recovery);
    expect($attempt->fresh()->status)->toBe('skipped')->and($attempt->fresh()->started_at)->toBeNull()
        ->and($attempt->fresh()->finished_at)->not->toBeNull()
        ->and($this->event->fresh()->status)->toBe($change === 'processed' ? 'processed' : 'failed');
    $this->assertDatabaseCount('wc_orders', 0);
    Http::assertNothingSent();
})->with(['paused', 'processed', 'unsigned']);
