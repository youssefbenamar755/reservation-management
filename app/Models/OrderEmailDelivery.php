<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEmailDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['snapshot', 'mime', 'fingerprint', 'deduplication_key', 'connection_key', 'gmail_message_id', 'tracking_token_hash', 'manual_review'];

    protected $casts = [
        'snapshot' => 'encrypted:array', 'mime' => 'encrypted',
        'expires_at' => 'datetime', 'sending_at' => 'datetime', 'sent_at' => 'datetime',
        'send_attempts' => 'integer',
        'tracking_enabled' => 'boolean', 'first_open_detected_at' => 'datetime',
        'workflow' => 'boolean', 'sync_attempts' => 'integer', 'sync_claimed_at' => 'datetime', 'next_sync_at' => 'datetime', 'synced_at' => 'datetime',
        'manual_review' => 'encrypted:array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(WcOrder::class, 'wc_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GmailConnection::class, 'gmail_connection_id');
    }

    public function preview(): array
    {
        $snapshot = $this->snapshot;

        $preview = [
            'id' => $this->id, 'recipient' => $snapshot['recipient'], 'sender' => $snapshot['sender'],
            'subject' => $snapshot['subject'], 'body' => $snapshot['body'],
            'attachments' => array_map(fn ($file) => ['name' => $file['name'], 'size' => $file['size']], $snapshot['attachments']),
            'expires_at' => $this->expires_at->toIso8601String(), 'status' => $this->status,
            'tracking' => $this->tracking(),
        ];
        if ($this->workflow) {
            $preview['attachments'] = $snapshot['attachments'];
            $preview += ['brand_name' => $snapshot['brand_name'] ?? '', 'html' => $snapshot['html'], 'text' => $snapshot['text'], 'reply_to' => $snapshot['reply_to'],
                'mode' => $snapshot['mode'], 'document_set_version' => $snapshot['document_set_version'],
                'config_version' => $snapshot['config_version'], 'will_complete' => $snapshot['mode'] === 'complete',
                'suppression' => $snapshot['mode'] === 'complete' ? 'Verified order-specific suppression required before completion.' : 'No completion notification is triggered by this action.',
                'recipient_override_reason' => $snapshot['recipient_override_reason'] ?? null, 'language' => $snapshot['language']];
        }

        return $preview;
    }

    public function outcome(): array
    {
        $captured = str_starts_with((string) $this->gmail_message_id, 'capture_');
        $outcome = ['id' => $this->id, 'status' => $this->status, 'message' => $this->result_message, 'sent_at' => $this->sent_at?->toIso8601String(), 'tracking' => $this->tracking()];
        if ($captured) {
            $outcome['captured'] = true;
            $outcome['message'] = 'Captured locally for validation — no Gmail message was sent. Order synchronization, if requested, is test-store only.';
        }
        if ($this->workflow) {
            $outcome += ['sync_status' => $this->sync_status, 'sync_error' => $this->sync_error,
                'review' => $this->manual_review,
                'gmail_url' => ! $captured && $this->status === 'sent' && $this->gmail_message_id ? 'https://mail.google.com/mail/?authuser='.rawurlencode($this->snapshot['gmail_account_email'] ?? '').'#sent/'.rawurlencode($this->gmail_message_id) : null];
        }

        return $outcome;
    }

    private function tracking(): array
    {
        return ['enabled' => (bool) $this->tracking_enabled, 'first_open_detected_at' => $this->first_open_detected_at?->toIso8601String()];
    }
}
