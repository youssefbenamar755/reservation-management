<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEmailDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['snapshot', 'mime', 'fingerprint', 'deduplication_key', 'connection_key', 'gmail_message_id', 'tracking_token_hash'];

    protected $casts = [
        'snapshot' => 'encrypted:array', 'mime' => 'encrypted',
        'expires_at' => 'datetime', 'sending_at' => 'datetime', 'sent_at' => 'datetime',
        'send_attempts' => 'integer',
        'tracking_enabled' => 'boolean', 'first_open_detected_at' => 'datetime',
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

        return [
            'id' => $this->id, 'recipient' => $snapshot['recipient'], 'sender' => $snapshot['sender'],
            'subject' => $snapshot['subject'], 'body' => $snapshot['body'],
            'attachments' => array_map(fn ($file) => ['name' => $file['name'], 'size' => $file['size']], $snapshot['attachments']),
            'expires_at' => $this->expires_at->toIso8601String(), 'status' => $this->status,
            'tracking' => $this->tracking(),
        ];
    }

    public function outcome(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'message' => $this->result_message, 'sent_at' => $this->sent_at?->toIso8601String(), 'tracking' => $this->tracking()];
    }

    private function tracking(): array
    {
        return ['enabled' => (bool) $this->tracking_enabled, 'first_open_detected_at' => $this->first_open_detected_at?->toIso8601String()];
    }
}
