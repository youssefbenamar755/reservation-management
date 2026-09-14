<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingContact extends Model
{
    protected $guarded = [];

    protected $casts = ['consented_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
}
