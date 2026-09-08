<?php

namespace App\Services;

use App\Exceptions\GmailDeliveryException;
use App\Models\GmailConnection;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebsiteEmailSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class OrderEmailComposer
{
    public const LIMITS = ['max_files' => 5, 'max_file_bytes' => 5242880, 'max_total_bytes' => 10485760];

    public function __construct(private GmailClient $gmail) {}

    public function options(WcOrder $order, User $actor): array
    {
        $connection = GmailConnection::where('user_id', $actor->id)->first();
        $setting = WebsiteEmailSetting::where('user_id', $actor->id)->where('website_id', $order->website_id)->first();
        $connected = $this->gmail->isConnected($connection);
        $sender = $connected ? $this->sender($connection->aliases ?? [], $setting?->sender_email) : null;
        $variables = ['{{customer_name}}' => $order->customer_name ?: '', '{{order_number}}' => (string) $order->wp_order_id, '{{website_name}}' => $order->website->name];
        $subject = strtr($setting?->subject_template ?: WebsiteEmailSetting::DEFAULT_SUBJECT, $variables);
        $body = strtr($setting?->body_template ?: WebsiteEmailSetting::DEFAULT_BODY, $variables);
        $signature = strtr($setting?->signature ?? WebsiteEmailSetting::DEFAULT_SIGNATURE, $variables);
        if (trim($signature) !== '') {
            $body .= "\n\n".$signature;
        }

        return [
            'connection' => ['connected' => $connected, 'email' => $connected ? $connection->email : null],
            'settings_url' => '/settings/email', 'sender' => $sender, 'recipient' => $order->customer_email ?? '',
            'subject' => $subject, 'body' => $body, 'limits' => self::LIMITS,
            'history' => $this->history($order, $actor),
        ];
    }

    /** @param array<UploadedFile> $files */
    public function prepare(WcOrder $order, User $actor, array $values, array $files): OrderEmailDelivery
    {
        // Check aggregate size before loading attachment bytes into memory.
        if (count($files) < 1 || count($files) > self::LIMITS['max_files']) {
            throw ValidationException::withMessages(['files' => 'Choose between one and five PDF files.']);
        }
        $total = 0;
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() < 1 || $file->getSize() > self::LIMITS['max_file_bytes']) {
                throw ValidationException::withMessages(['files' => 'Each PDF must be no larger than 5 MiB.']);
            }
            $total += $file->getSize();
        }
        if ($total > self::LIMITS['max_total_bytes']) {
            throw ValidationException::withMessages(['files' => 'The PDF files must total no more than 10 MiB.']);
        }

        $connection = GmailConnection::where('user_id', $actor->id)->first();
        if (! $this->gmail->isConnected($connection)) {
            throw ValidationException::withMessages(['sender' => 'Connect your Gmail account in Email settings first.']);
        }
        $setting = WebsiteEmailSetting::where('user_id', $actor->id)->where('website_id', $order->website_id)->first();
        if (! $setting?->sender_email) {
            throw ValidationException::withMessages(['sender' => 'Choose a sender for this website in Email settings first.']);
        }
        try {
            $sender = $this->sender($this->gmail->aliases($connection), $setting->sender_email);
        } catch (GmailDeliveryException $exception) {
            throw ValidationException::withMessages(['sender' => $exception->getMessage()]);
        }
        if ($sender === null) {
            throw ValidationException::withMessages(['sender' => 'The website sender is no longer available in your Gmail account. Update Email settings before continuing.']);
        }

        $attachments = [];
        $contents = [];
        foreach ($files as $file) {
            $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
                throw ValidationException::withMessages(['files' => 'PDF filenames must be no longer than 255 bytes and cannot contain control characters.']);
            }
            $bytes = $file->getContent();
            if (! str_starts_with($bytes, '%PDF-') || $file->getMimeType() !== 'application/pdf') {
                throw ValidationException::withMessages(['files' => 'Only PDF documents are supported.']);
            }
            $attachments[] = ['name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            $contents[] = $bytes;
        }
        $snapshot = ['recipient' => $values['recipient'], 'sender' => $sender, 'subject' => $values['subject'], 'body' => $values['body'], 'attachments' => $attachments];
        // Keep the existing fingerprint for untracked messages, including older previews.
        if ((bool) ($values['track_opens'] ?? false)) {
            $snapshot['track_opens'] = true;
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $order->id, $connection->connection_key, $snapshot], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // One order lock serializes duplicate preparations, including a just-expired preview.
        return DB::transaction(function () use ($order, $actor, $connection, $snapshot, $fingerprint, $contents) {
            $currentOrder = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $currentOrder);
            if ($currentOrder->website_id !== $order->website_id) {
                throw ValidationException::withMessages(['order' => 'The order website changed. Reload the order before preparing an email.']);
            }
            $existing = OrderEmailDelivery::where('deduplication_key', $fingerprint)->lockForUpdate()->first();
            if ($existing) {
                $this->expire($existing);
                if ($existing->status !== 'expired') {
                    return $existing;
                }
            }
            $id = (string) Str::uuid();
            $message = (new Email)->from(new Address($snapshot['sender']['email'], $snapshot['sender']['name']))
                ->to($snapshot['recipient'])->subject($snapshot['subject'])->text($snapshot['body']);
            $trackingToken = null;
            if ($snapshot['track_opens'] ?? false) {
                $trackingToken = bin2hex(random_bytes(32));
                // Use the configured application origin, never an incoming Host header.
                $pixelUrl = rtrim((string) config('app.url'), '/').route('emails.opens.show', ['token' => $trackingToken], absolute: false);
                $body = nl2br(htmlspecialchars($snapshot['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
                $message->html('<!doctype html><html><body><div>'.$body.'</div><img src="'.htmlspecialchars($pixelUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0"></body></html>');
            }
            $message->getHeaders()->addIdHeader('Message-ID', $id.'@wphub.website');
            foreach ($snapshot['attachments'] as $index => $file) {
                $message->attach($contents[$index], $file['name'], 'application/pdf');
            }

            return OrderEmailDelivery::create([
                'id' => $id, 'user_id' => $actor->id, 'wc_order_id' => $order->id, 'website_id' => $order->website_id,
                'gmail_connection_id' => $connection->id, 'connection_key' => $connection->connection_key,
                'fingerprint' => $fingerprint, 'deduplication_key' => $fingerprint, 'snapshot' => $snapshot, 'mime' => $message->toString(),
                'status' => 'prepared', 'result_message' => 'Review the saved email, then confirm Send.', 'expires_at' => now()->addDay(),
                'tracking_enabled' => $trackingToken !== null,
                'tracking_token_hash' => $trackingToken !== null ? hash('sha256', $trackingToken) : null,
            ]);
        }, 3);
    }

    public function expire(OrderEmailDelivery $delivery): void
    {
        if (in_array($delivery->status, ['prepared', 'failed'], true) && $delivery->expires_at->isPast()) {
            $delivery->update(['status' => 'expired', 'mime' => null, 'deduplication_key' => null, 'result_message' => 'This preview expired. Prepare a new email before sending.']);
        } elseif ($delivery->status === 'sending' && $delivery->sending_at?->lt(now()->subMinutes(5))) {
            $delivery->update(['status' => 'uncertain', 'result_message' => 'The send result is unknown. Check Gmail Sent before taking any further action. This email will not be sent again automatically.']);
        }
        if ($delivery->status === 'uncertain' && $delivery->expires_at->isPast()) {
            $delivery->update(['mime' => null]);
        }
    }

    public function refreshStatus(OrderEmailDelivery $delivery): OrderEmailDelivery
    {
        return DB::transaction(function () use ($delivery) {
            $current = OrderEmailDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $this->expire($current);

            return $current;
        }, 3);
    }

    public function sender(array $aliases, ?string $email): ?array
    {
        foreach ($aliases as $alias) {
            if ($email !== null && strcasecmp($alias['email'] ?? '', $email) === 0) {
                if (! filter_var($alias['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $alias['email'].($alias['name'] ?? ''))) {
                    return null;
                }

                return ['email' => $alias['email'], 'name' => $alias['name'] ?? ''];
            }
        }

        return null;
    }

    private function history(WcOrder $order, User $actor): array
    {
        $scope = OrderEmailDelivery::where('wc_order_id', $order->id)->where('user_id', $actor->id);
        // Conditional bulk expiry avoids both per-row MIME loads and stale updates racing a send.
        (clone $scope)->whereIn('status', ['prepared', 'failed'])->where('expires_at', '<', now())->update([
            'status' => 'expired', 'mime' => null, 'deduplication_key' => null, 'result_message' => 'This preview expired. Prepare a new email before sending.',
        ]);
        (clone $scope)->where('status', 'sending')->where('sending_at', '<', now()->subMinutes(5))->update([
            'status' => 'uncertain', 'result_message' => 'The send result is unknown. Check Gmail Sent before taking any further action. This email will not be sent again automatically.',
        ]);
        (clone $scope)->where('status', 'uncertain')->where('expires_at', '<', now())->whereNotNull('mime')->update(['mime' => null]);

        return $scope
            ->select('id', 'snapshot', 'status', 'result_message', 'expires_at', 'sending_at', 'sent_at', 'created_at', 'tracking_enabled', 'first_open_detected_at')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()->map(function ($delivery) {
                $preview = $delivery->preview();
                unset($preview['body']);

                return $preview + ['message' => $delivery->result_message, 'created_at' => $delivery->created_at->toIso8601String(), 'sent_at' => $delivery->sent_at?->toIso8601String()];
            })->all();
    }
}
