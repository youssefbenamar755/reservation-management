<?php

namespace App\Models;

use App\Support\FluentWebhookPayload;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class FfSubmission extends Model
{
    protected $fillable = [
        'website_id',
        'form_id',
        'entry_id',
        'email',
        'payment_status',
        'amount',
        'created_at_wp',
        'payload',
        'amadeus_command_block',
        'amadeus_generated_at',
        'pnr',
        'pnr_generated_at',
        'pnr_pdf_path',
        'pnr_source',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at_wp' => 'datetime',
        'amadeus_generated_at' => 'datetime',
        'pnr_generated_at' => 'datetime',
    ];

    protected function payload(): Attribute
    {
        // Legacy webhook rows may contain the URL token. Never return it in
        // Inertia props, JSON, exports, or subsequent writes of this payload.
        return Attribute::make(
            get: fn ($value) => FluentWebhookPayload::redact(json_decode($value ?? '{}', true) ?? []),
            set: fn ($value) => json_encode(FluentWebhookPayload::redact(
                is_array($value) ? $value : (json_decode($value ?? '{}', true) ?? [])
            ), JSON_THROW_ON_ERROR),
        );
    }

    public function website()
    {
        return $this->belongsTo(Website::class);
    }
}
