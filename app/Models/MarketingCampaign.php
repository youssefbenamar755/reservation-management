<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingCampaign extends Model
{
    protected $guarded = [];

    protected $hidden = ['connection_key'];

    protected $casts = ['content' => 'array', 'audience' => 'array', 'scheduled_at' => 'datetime', 'sending_at' => 'datetime', 'submitted_at' => 'datetime', 'test_sent_at' => 'datetime'];

    public function recipients()
    {
        return $this->hasMany(MarketingRecipient::class);
    }
}
