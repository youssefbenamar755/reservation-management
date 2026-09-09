<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token', 'app_fingerprint', 'connection_key', 'catalog'];

    protected $casts = [
        'access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'catalog' => 'array',
        'expires_at' => 'datetime', 'connected_at' => 'datetime', 'catalog_at' => 'datetime',
        'reconnect_required' => 'boolean',
    ];
}
