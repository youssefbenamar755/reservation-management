<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['api_key', 'webhook_secret', 'connection_key'];

    protected $casts = ['api_key' => 'encrypted', 'webhook_secret' => 'encrypted', 'senders' => 'array', 'verified_at' => 'datetime', 'last_event_at' => 'datetime'];

    public function ready(): bool
    {
        return (bool) ($this->api_key && $this->verified_at && $this->folder_id && $this->webhook_id);
    }
}
