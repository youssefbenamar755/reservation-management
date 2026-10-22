<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentTestSend extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['snapshot', 'provider_message_id'];

    protected $casts = ['snapshot' => 'encrypted:array'];
}
