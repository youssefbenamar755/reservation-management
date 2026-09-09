<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsefulAlert extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['user_id', 'episode_key', 'notification_id'];

    protected function casts(): array
    {
        return ['count' => 'integer', 'first_detected_at' => 'immutable_datetime', 'last_detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime', 'snoozed_until' => 'immutable_datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
