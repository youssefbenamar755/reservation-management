<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoReport extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['cache_key', 'connection_key', 'mapping_key', 'app_fingerprint', 'run_key', 'payload', 'page_url'];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'refreshed_at' => 'immutable_datetime',
        ];
    }
}
