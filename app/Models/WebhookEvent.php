<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = [
        'website_id',
        'source',
        'topic',
        'external_id',
        'signature_valid',
        'status',
        'received_at',
        'processed_at',
        'payload',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function website()
    {
        return $this->belongsTo(Website::class);
    }
}
