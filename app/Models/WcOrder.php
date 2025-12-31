<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WcOrder extends Model
{
    protected $fillable = [
        'website_id',
        'wp_order_id',
        'status',
        'payment_status',
        'currency',
        'total',
        'customer_email',
        'customer_name',
        'created_at_wp',
        'updated_at_wp',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at_wp' => 'datetime',
        'updated_at_wp' => 'datetime',
        'total' => 'decimal:2',
    ];

    public function website()
    {
        return $this->belongsTo(Website::class);
    }
}
