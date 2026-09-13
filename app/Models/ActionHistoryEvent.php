<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActionHistoryEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'occurred_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
}
