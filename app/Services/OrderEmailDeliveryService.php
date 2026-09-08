<?php

namespace App\Services;

use App\Exceptions\GmailDeliveryException;
use App\Models\GmailConnection;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebsiteEmailSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderEmailDeliveryService
{
    public const UNKNOWN = 'The send result is unknown. Check Gmail Sent before taking any further action. This email will not be sent again automatically.';

    public function __construct(private GmailClient $gmail, private OrderEmailComposer $composer) {}

    public function send(WcOrder $order, OrderEmailDelivery $delivery, User $actor): OrderEmailDelivery
    {
        [$current, $claimed] = DB::transaction(function () use ($order, $delivery, $actor) {
            $current = OrderEmailDelivery::whereKey($delivery->id)->where('wc_order_id', $order->id)->where('user_id', $actor->id)
                ->where('website_id', $order->website_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $order->fresh());
            $this->composer->expire($current);
            if (! in_array($current->status, ['prepared', 'failed'], true)) {
                return [$current, false];
            }
            if ($current->mime === null) {
                $current->update(['status' => 'expired', 'deduplication_key' => null, 'result_message' => 'This preview expired. Prepare a new email before sending.']);

                return [$current, false];
            }
            $current->update(['status' => 'sending', 'sending_at' => now(), 'send_attempts' => $current->send_attempts + 1, 'result_message' => 'Gmail is processing this send request.']);

            return [$current, true];
        }, 3);
        if (! $claimed) {
            return $current;
        }

        $sendStarted = false;
        try {
            $connection = GmailConnection::whereKey($current->gmail_connection_id)->where('user_id', $actor->id)->first();
            if (! $this->gmail->isConnected($connection) || $connection->connection_key !== $current->connection_key) {
                throw new GmailDeliveryException('The Gmail connection has changed. Connect Gmail and prepare a new preview before sending.');
            }
            $snapshot = $current->snapshot;
            $setting = WebsiteEmailSetting::where('user_id', $actor->id)->where('website_id', $current->website_id)->first();
            if (! $setting?->sender_email || strcasecmp($setting->sender_email, $snapshot['sender']['email']) !== 0) {
                throw new GmailDeliveryException('The website sender has changed. Prepare a new preview before sending.');
            }
            if ($this->composer->sender($this->gmail->aliases($connection), $snapshot['sender']['email']) === null) {
                throw new GmailDeliveryException('This sender is no longer available in Gmail. Update Email settings before trying again.');
            }
            $connection->refresh();
            if (! $this->gmail->isConnected($connection) || $connection->connection_key !== $current->connection_key) {
                throw new GmailDeliveryException('The Gmail connection has changed. Prepare a new preview before sending.');
            }
            $sendStarted = true;
            $messageId = $this->gmail->send($connection, $current->mime);
            if ($messageId === '') {
                throw new GmailDeliveryException(self::UNKNOWN, true);
            }
            // Gmail now owns the message and PDFs. Retain only the reviewed metadata locally.
            $current->update(['status' => 'sent', 'sent_at' => now(), 'gmail_message_id' => $messageId, 'mime' => null, 'result_message' => 'Gmail accepted the email. It is available in Sent.']);
        } catch (GmailDeliveryException $exception) {
            $current->update(['status' => $exception->uncertain ? 'uncertain' : 'failed', 'result_message' => $exception->uncertain ? self::UNKNOWN : $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::error('Order email attempt could not be confirmed', ['delivery_id' => $current->id, 'exception_type' => $exception::class]);
            $current->update([
                'status' => $sendStarted ? 'uncertain' : 'failed',
                'result_message' => $sendStarted ? self::UNKNOWN : 'The email could not be sent. Check Email settings before trying again.',
            ]);
        }

        // A mail client may request the image while Gmail is completing the send.
        return $current->refresh();
    }
}
