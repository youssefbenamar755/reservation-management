<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficWebsite extends Model
{
    protected $guarded = [];

    protected $hidden = ['connection_key', 'mapping_key'];
}
