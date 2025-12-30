<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FfSubmission extends Model
{
    protected $fillable = [
        'website_id',
        'form_id',
        'entry_id',
        'email',
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
        'payload' => 'array', // CRITICAL: Cast JSON to array so payload['response'] is accessible
        'created_at_wp' => 'datetime',
        'amadeus_generated_at' => 'datetime',
        'pnr_generated_at' => 'datetime',
    ];

    public function website()
    {
        return $this->belongsTo(Website::class);
    }
}
