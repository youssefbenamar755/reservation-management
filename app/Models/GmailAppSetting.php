<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailAppSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['client_secret'];

    protected $casts = ['client_secret' => 'encrypted'];
}
