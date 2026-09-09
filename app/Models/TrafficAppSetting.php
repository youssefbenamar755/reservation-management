<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficAppSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['client_secret'];

    protected $casts = ['client_secret' => 'encrypted'];
}
