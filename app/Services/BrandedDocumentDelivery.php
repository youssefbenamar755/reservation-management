<?php

namespace App\Services;

use App\Exceptions\GmailDeliveryException;
use App\Models\GmailConnection;
use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebsiteDocumentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class BrandedDocumentDelivery
{
    private const NETWORK_BUDGET_SECONDS = 20;

    public function __construct(private GmailClient $gmail, private OrderDocumentStorage $storage) {}

    public function context(WcOrder $order, User $actor): array
    {
        Gate::forUser($actor)->authorize('view', $order);
        $profile = app(WebsiteDocumentProfiles::class)->draft($order->website);
        $readiness = app(WebsiteDocumentProfiles::class)->readiness($profile);
        $documents = OrderDocument::where('website_id', $order->website_id)->where('wc_order_id', $order->id)
            ->where('customer_email', strtolower($order->customer_email ?? ''))->whereNotNull('approved_at')
            ->orderByDesc('version')->limit(100)->get()->map->summary()->all();
        $history = OrderEmailDelivery::where('website_id', $order->website_id)->where('wc_order_id', $order->id)->where('workflow', true)
            ->select(['id', 'snapshot', 'status', 'result_message', 'expires_at', 'sending_at', 'sent_at', 'created_at', 'tracking_enabled',
                'first_open_detected_at', 'workflow', 'sync_status', 'sync_error', 'gmail_message_id', 'document_set_version', 'document_mode', 'manual_review'])
            ->latest()->limit(20)->get()->map(fn ($row) => $row->preview() + $row->outcome())->all();

        return ['enabled' => (bool) $profile->workflow_enabled, 'readiness' => $readiness['missing'],
            'language' => app(BrandedDocumentRenderer::class)->render($profile, $order, [])['language'],
            'profile' => $profile->only(['brand_name', 'sender_email', 'sender_name', 'reply_to', 'default_language', 'config_version']),
            'documents' => $documents, 'required_services' => $profile->required_services ?? [], 'deliveries' => $history,
            'document_set_version' => null, 'store' => [
                'supported' => $readiness['platform'] === 'woocommerce',
                'verified' => $this->completionVerified($profile),
                'message' => $this->completionVerified($profile) ? 'Order-specific completion suppression verified.' : 'Completion suppression not configured. Send-only is available.',
            ]];
    }

    public function prepare(WcOrder $order, User $actor, array $values): OrderEmailDelivery
    {
        $deadline = $this->monotonicSeconds() + self::NETWORK_BUDGET_SECONDS;
        Gate::forUser($actor)->authorize('update', $order);
        $profile = $this->profile($order);
        $mode = $values['mode'];
        $connection = $this->connection($profile, $order);
        $sender = $this->verifiedSender($connection, $profile, $deadline);
        $ids = array_values(array_unique($values['document_ids']));
        $documents = OrderDocument::whereIn('id', $ids)->where('website_id', $order->website_id)->where('wc_order_id', $order->id)
            ->whereNotNull('approved_at')->where('customer_email', strtolower($order->customer_email ?? ''))->orderBy('id')->get();
        if ($documents->count() !== count($ids) || $documents->isEmpty() || $documents->count() > 5 || $documents->sum('size') > OrderEmailComposer::LIMITS['max_total_bytes']) {
            throw ValidationException::withMessages(['document_ids' => 'Select one to five approved PDFs belonging to this exact order and customer, totalling at most 10 MiB.']);
        }
        if (strcasecmp($values['recipient'], $order->customer_email ?? '') !== 0 && ! filled($values['recipient_override_reason'] ?? null)) {
            throw ValidationException::withMessages(['recipient_override_reason' => 'Explain and confirm the change from the recorded customer email.']);
        }
        $required = $profile->required_services ?? [];
        $fulfilled = $values['fulfilled_services'] ?? [];
        $remote = null;
        if ($mode === 'complete') {
            if (! $this->completionVerified($profile)) {
                throw ValidationException::withMessages(['mode' => 'Completion suppression not configured. Use send selected documents — keep order processing.']);
            }
            if ($required === [] || empty($values['fulfillment_confirmed']) || array_diff($required, $fulfilled)) {
                throw ValidationException::withMessages(['fulfilled_services' => 'Confirm that every required service and document for this order is included. Use partial delivery if anything remains.']);
            }
            $remote = app(DocumentStoreGateway::class)->checkOrder($order, $deadline);
            $this->assertEligible($remote, $order);
        }
        $attachments = $documents->map->summary()->all();
        $contents = $documents->map(fn ($document) => $this->storage->bytes($document))->all();
        $version = hash('sha256', json_encode(array_map(fn ($doc) => [$doc['id'], $doc['sha256'], $doc['version']], $attachments), JSON_THROW_ON_ERROR));
        $rendered = app(BrandedDocumentRenderer::class)->render($profile, $order, $attachments, $mode === 'correction' ? 'correction' : 'delivery', $values['language'] ?? null);
        $snapshot = [
            'recipient' => $values['recipient'], 'recipient_override_reason' => $values['recipient_override_reason'] ?? null,
            'original_recipient' => $order->customer_email, 'sender' => $sender, 'reply_to' => $profile->reply_to,
            'brand_name' => $profile->brand_name, 'gmail_account_email' => $connection->email,
            'subject' => $rendered['subject'], 'body' => $rendered['text'], 'text' => $rendered['text'], 'html' => $rendered['html'],
            'language' => $rendered['language'], 'attachments' => $attachments, 'mode' => $mode, 'document_set_version' => $version,
            'config_version' => $profile->config_version, 'website_identity' => $profile->store_pairing_key,
            'required_services' => $required, 'fulfilled_services' => $fulfilled, 'fulfillment_confirmed' => $mode === 'complete' && ! empty($values['fulfillment_confirmed']),
            'expected_modified_gmt' => $remote['modified_gmt'] ?? $remote['date_modified_gmt'] ?? $order->payload['date_modified_gmt'] ?? null,
            'expected_fulfillment_revision' => $remote['fulfillment_revision'] ?? null,
            'previous_delivery_id' => $values['previous_delivery_id'] ?? null,
        ];

        return DB::transaction(function () use ($order, $actor, $connection, $snapshot, $contents, $values, $version, $mode) {
            $locked = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->website_id !== $order->website_id || $locked->customer_email !== $snapshot['original_recipient']) {
                throw ValidationException::withMessages(['order' => 'The order changed. Reload and prepare again.']);
            }
            $prior = null;
            if (in_array($mode, ['resend', 'correction'], true)) {
                $prior = OrderEmailDelivery::whereKey($values['previous_delivery_id'] ?? null)->where('website_id', $order->website_id)
                    ->where('wc_order_id', $order->id)->where('workflow', true)->whereIn('status', ['sent', 'reviewed_sent'])->first();
                if (! $prior || empty($values['resend_confirmed'])) {
                    throw ValidationException::withMessages(['previous_delivery_id' => 'Choose a previously sent document email and explicitly confirm the resend or correction.']);
                }
            }
            if (! $prior && OrderEmailDelivery::where('website_id', $order->website_id)->where('wc_order_id', $order->id)
                ->where('workflow', true)->where('document_set_version', $version)->whereIn('status', ['sent', 'reviewed_sent'])->exists()) {
                throw ValidationException::withMessages(['documents' => 'These documents were already sent. Use the explicit resend or corrected-documents action.']);
            }
            // Cross-operator duplicate protection: external order numbers alone are never a key.
            $key = hash('sha256', json_encode([$order->website_id, $order->id, $connection->connection_key, $snapshot], JSON_THROW_ON_ERROR));
            $existing = OrderEmailDelivery::where('deduplication_key', $key)->lockForUpdate()->first();
            if ($existing) {
                app(OrderEmailComposer::class)->expire($existing);
                if ($existing->status !== 'expired') {
                    return $existing;
                }
            }
            $id = (string) Str::uuid();
            $message = (new Email)->from(new Address($snapshot['sender']['email'], $snapshot['sender']['name']))
                ->to($snapshot['recipient'])->replyTo($snapshot['reply_to'])->subject($snapshot['subject'])->text($snapshot['text'])->html($snapshot['html']);
            $message->getHeaders()->addIdHeader('Message-ID', $id.'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'wphub.invalid'));
            foreach ($snapshot['attachments'] as $index => $attachment) {
                $message->attach($contents[$index], $attachment['name'], 'application/pdf');
            }

            return OrderEmailDelivery::create([
                'id' => $id, 'user_id' => $actor->id, 'website_id' => $order->website_id, 'wc_order_id' => $order->id,
                'gmail_connection_id' => $connection->id, 'connection_key' => $connection->connection_key,
                'fingerprint' => $key, 'deduplication_key' => $key, 'snapshot' => $snapshot, 'mime' => $message->toString(),
                'workflow' => true, 'document_mode' => $mode, 'document_set_version' => $version,
                'status' => 'prepared', 'sync_status' => 'not_requested', 'expires_at' => now()->addDay(),
                'result_message' => 'Review the exact saved email and attachments before confirming Send.',
            ]);
        }, 3);
    }

    public function send(WcOrder $order, OrderEmailDelivery $delivery, User $actor): OrderEmailDelivery
    {
        $deadline = $this->monotonicSeconds() + self::NETWORK_BUDGET_SECONDS;
        Gate::forUser($actor)->authorize('update', $order);
        [$current, $claimed] = DB::transaction(function () use ($order, $delivery, $actor) {
            $lockedOrder = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $lockedOrder);
            $current = OrderEmailDelivery::whereKey($delivery->id)->where('wc_order_id', $lockedOrder->id)->where('website_id', $lockedOrder->website_id)
                ->where('workflow', true)->lockForUpdate()->firstOrFail();
            app(OrderEmailComposer::class)->expire($current);
            if (! in_array($current->status, ['prepared', 'failed'], true)) {
                return [$current, false];
            }
            $others = OrderEmailDelivery::where('wc_order_id', $order->id)->where('website_id', $order->website_id)->where('workflow', true)->where('id', '!=', $current->id);
            if ((clone $others)->whereIn('status', ['sending', 'uncertain'])->exists()) {
                throw ValidationException::withMessages(['delivery' => 'Another send is in progress or has an unknown outcome. Review it before sending again.']);
            }
            if (! in_array($current->document_mode, ['resend', 'correction'], true) && (clone $others)->where('document_set_version', $current->document_set_version)->whereIn('status', ['sent', 'reviewed_sent'])->exists()) {
                throw ValidationException::withMessages(['delivery' => 'These documents were already sent. Use an explicit resend.']);
            }
            $profile = $this->profile($lockedOrder);
            $snapshot = $current->snapshot;
            if ($profile->config_version !== $snapshot['config_version'] || $lockedOrder->customer_email !== $snapshot['original_recipient'] || ! $current->mime) {
                throw ValidationException::withMessages(['delivery' => 'The configuration or customer changed. Prepare a new preview.']);
            }
            $this->assertApprovedDocuments($lockedOrder, $snapshot);
            $current->update(['user_id' => $actor->id, 'status' => 'sending', 'sending_at' => now(), 'send_attempts' => $current->send_attempts + 1,
                'result_message' => 'Sending the approved document email.']);
            app(ActionHistoryRecorder::class)->beginEmail($current, $lockedOrder);

            return [$current, true];
        }, 3);
        if (! $claimed) {
            return $current;
        }
        $started = false;
        try {
            $profile = $this->profile($order->fresh());
            if ((int) $profile->config_version !== (int) $current->snapshot['config_version']) {
                throw new GmailDeliveryException('The website configuration changed. Prepare a new preview.');
            }
            $connection = $this->connection($profile, $order);
            if ($connection->id !== $current->gmail_connection_id || $connection->connection_key !== $current->connection_key) {
                throw new GmailDeliveryException('The Gmail connection changed. Prepare a new preview.');
            }
            $sender = $this->verifiedSender($connection, $profile, $deadline);
            if (strcasecmp($sender['email'], $current->snapshot['sender']['email']) !== 0) {
                throw new GmailDeliveryException('The approved sender changed. Prepare a new preview.');
            }
            // Read-only store recheck before sending; completion rechecks again after sending.
            if ($current->document_mode === 'complete') {
                $remote = app(DocumentStoreGateway::class)->checkOrder($order, $deadline);
                $this->assertEligible($remote, $order);
                $expectedRevision = $current->snapshot['expected_fulfillment_revision'] ?? null;
                if (($remote['modified_gmt'] ?? null) !== $current->snapshot['expected_modified_gmt']
                    || ! is_string($expectedRevision) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedRevision)
                    || ! hash_equals($expectedRevision, $remote['fulfillment_revision'])) {
                    throw new GmailDeliveryException('The store order changed after preview. Prepare a new preview before sending.');
                }
            }
            $started = true;
            $messageId = $this->gmail->send($connection, $current->mime, function () use ($order, $current, $actor) {
                // OAuth refresh can also be slow: this runs after refresh and directly before
                // transmission, so an intervening disable or authorization change is respected.
                try {
                    $this->assertReadyToTransmit($order, $current, $actor);
                } catch (GmailDeliveryException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    // The transport has not submitted MIME yet. This is a definite failure,
                    // not an uncertain Gmail outcome that would require mailbox review.
                    throw new GmailDeliveryException('Send validation failed. Check configuration, access and approved documents before retrying.');
                }
            }, $deadline);
            if ($messageId === '') {
                throw new GmailDeliveryException(OrderEmailDeliveryService::UNKNOWN, true);
            }
            $current->update(['status' => 'sent', 'sent_at' => now(), 'gmail_message_id' => $messageId, 'mime' => null,
                'sync_status' => $current->document_mode === 'complete' ? 'pending' : 'not_requested',
                'next_sync_at' => $current->document_mode === 'complete' ? now() : null,
                'result_message' => $current->document_mode === 'complete' ? 'Documents sent — order update pending.' : 'Documents sent. Order status was not changed.']);
        } catch (GmailDeliveryException $exception) {
            $current->update(['status' => $exception->uncertain ? 'uncertain' : 'failed', 'result_message' => $exception->uncertain ? OrderEmailDeliveryService::UNKNOWN : $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::warning('Branded document send could not be confirmed', ['delivery_id' => $current->id, 'exception_type' => $exception::class]);
            $current->update(['status' => $started ? 'uncertain' : 'failed', 'result_message' => $started ? OrderEmailDeliveryService::UNKNOWN : 'Send validation failed. Check configuration, sender, and current order status before retrying.']);
        }
        app(ActionHistoryRecorder::class)->finishEmail($current);
        if ($current->status === 'sent' && $current->sync_status === 'pending') {
            try {
                \App\Jobs\SyncDocumentCompletion::dispatch($current->id)->afterCommit();
            } catch (Throwable $exception) {
                // Gmail has already accepted the message. The scheduled recovery sees the durable pending sync.
                Log::warning('Document completion queue unavailable; pending sync retained', ['delivery_id' => $current->id, 'exception_type' => $exception::class]);
            }
        }

        return $current->fresh();
    }

    public function profile(WcOrder $order): WebsiteDocumentProfile
    {
        $profile = WebsiteDocumentProfile::where('website_id', $order->website_id)->first();
        if (! $profile || ! $profile->workflow_enabled) {
            throw ValidationException::withMessages(['profile' => 'Activate this website’s Email & Documents workflow first.']);
        }
        $readiness = app(WebsiteDocumentProfiles::class)->readiness($profile);
        if (! $readiness['ready']) {
            throw ValidationException::withMessages(['profile' => 'Configuration required: '.implode(', ', $readiness['missing'])]);
        }

        return $profile;
    }

    private function completionVerified(WebsiteDocumentProfile $profile): bool
    {
        return app(WebsiteDocumentProfiles::class)->readiness($profile)['completion_supported'];
    }

    private function assertReadyToTransmit(WcOrder $order, OrderEmailDelivery $delivery, User $actor): void
    {
        DB::transaction(function () use ($order, $delivery, $actor) {
            $currentOrder = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // Profile edits serialize on the website, including the emergency disable.
            $currentOrder->setRelation('website', $currentOrder->website()->lockForUpdate()->firstOrFail());
            Gate::forUser($actor->fresh())->authorize('update', $currentOrder);
            $profile = $this->profile($currentOrder);
            $connection = $this->connection($profile, $currentOrder);
            if ((int) $profile->config_version !== (int) $delivery->snapshot['config_version']
                || $connection->id !== $delivery->gmail_connection_id || $connection->connection_key !== $delivery->connection_key) {
                throw new GmailDeliveryException('The website configuration or Gmail connection changed. Prepare a new preview.');
            }
            if ($delivery->document_mode === 'complete' && ! $this->completionVerified($profile)) {
                throw new GmailDeliveryException('Completion suppression is no longer verified. Check the companion plugin before preparing a new preview.');
            }
            $this->assertApprovedDocuments($currentOrder, $delivery->snapshot);
        }, 3);
    }

    private function assertApprovedDocuments(WcOrder $order, array $snapshot): void
    {
        $attachments = $snapshot['attachments'] ?? [];
        if (! $attachments || $order->customer_email !== ($snapshot['original_recipient'] ?? null)) {
            throw ValidationException::withMessages(['documents' => 'The approved documents or customer changed. Prepare a new preview.']);
        }
        $documents = OrderDocument::whereIn('id', array_column($attachments, 'id'))
            ->where('website_id', $order->website_id)->where('wc_order_id', $order->id)
            ->where('customer_email', strtolower($order->customer_email ?? ''))->whereNotNull('approved_at')->whereNotNull('approved_by')
            ->get()->keyBy('id');
        if ($documents->count() !== count($attachments)) {
            throw ValidationException::withMessages(['documents' => 'A selected PDF is no longer approved for this order and customer. Prepare a new preview.']);
        }
        foreach ($attachments as $attachment) {
            $document = $documents->get($attachment['id']);
            if (! $document || (int) $document->version !== (int) ($attachment['version'] ?? -1)
                || (int) $document->size !== (int) ($attachment['size'] ?? -1) || $document->name !== ($attachment['name'] ?? null)
                || ! hash_equals($document->sha256, (string) ($attachment['sha256'] ?? ''))) {
                throw ValidationException::withMessages(['documents' => 'An approved document version changed. Prepare a new preview.']);
            }
        }
        // The MIME contains the exact bytes already reviewed. Never replace them with mutable storage contents here.
    }

    private function assertEligible(array $remote, WcOrder $order): void
    {
        if (($remote['status'] ?? '') !== 'processing' || ($remote['paid'] ?? false) !== true
            || strcasecmp($remote['customer_email'] ?? '', $order->customer_email ?? '') !== 0) {
            throw new GmailDeliveryException('Completion requires a verified paid, processing order for this customer. Refresh the order or choose send-only.');
        }
        if (! is_string($remote['fulfillment_revision'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $remote['fulfillment_revision'])) {
            throw new GmailDeliveryException('The store did not provide a verified fulfillment revision. Check the companion plugin before preparing a completion email.');
        }
    }

    private function connection(WebsiteDocumentProfile $profile, WcOrder $order): GmailConnection
    {
        $connection = GmailConnection::find($profile->gmail_connection_id);
        if (! $this->gmail->isConnected($connection) || ! app(WebsiteDocumentProfiles::class)->connectionAuthorized($profile)) {
            throw new GmailDeliveryException('Connect and explicitly approve a Gmail account for this website.');
        }

        return $connection;
    }

    private function verifiedSender(GmailConnection $connection, WebsiteDocumentProfile $profile, float $deadline): array
    {
        $sender = app(OrderEmailComposer::class)->sender($this->gmail->aliases($connection, $deadline), $profile->sender_email);
        if (! $sender) {
            throw new GmailDeliveryException('This website’s sender is not a verified Gmail alias. Check Email & Documents settings.');
        }
        $sender['name'] = $profile->sender_name;

        return $sender;
    }

    protected function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
