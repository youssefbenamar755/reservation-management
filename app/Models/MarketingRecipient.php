<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingRecipient extends Model
{
    protected $guarded = [];

    protected $casts = ['delivered_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime', 'bounced_at' => 'datetime', 'unsubscribed_at' => 'datetime', 'complained_at' => 'datetime'];
}
