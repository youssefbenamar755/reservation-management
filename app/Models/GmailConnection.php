<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token', 'app_fingerprint', 'connection_key'];

    protected $casts = [
        'access_token' => 'encrypted', 'refresh_token' => 'encrypted',
        'expires_at' => 'datetime', 'connected_at' => 'datetime', 'aliases' => 'array',
    ];
}
