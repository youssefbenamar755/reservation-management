<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FfForm extends Model
{
    protected $fillable = [
        'website_id',
        'form_id',
        'title',
        'fields',
    ];

    protected $casts = [
        'fields' => 'array',
    ];

    public function website()
    {
        return $this->belongsTo(Website::class);
    }
}
