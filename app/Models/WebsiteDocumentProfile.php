<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteDocumentProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'templates' => 'array', 'required_services' => 'array', 'store_capability' => 'array',
        'last_test_result' => 'array', 'workflow_enabled' => 'boolean', 'config_version' => 'integer',
        'brand_sync_version' => 'integer', 'store_checked_at' => 'datetime', 'last_test_at' => 'datetime',
    ];

    public function website()
    {
        return $this->belongsTo(Website::class);
    }

    public function gmailConnection()
    {
        return $this->belongsTo(GmailConnection::class);
    }
}
