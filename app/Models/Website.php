<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    protected $fillable = [
        'user_id','name','slug','base_url',
        'wc_consumer_key','wc_consumer_secret','wc_webhook_secret',
        'ff_api_key','ff_username','ff_app_password','webhook_secret',
        'timezone','status',
        'last_sync_at','last_webhook_at',
    ];

    /**
     * Never expose credentials / secrets in JSON (e.g. Inertia page props).
     */
    protected $hidden = [
        'wc_consumer_key',
        'wc_consumer_secret',
        'wc_webhook_secret',
        'ff_api_key',
        'ff_username',
        'ff_app_password',
        'webhook_secret',
    ];

    protected $casts = [
        'wc_consumer_key' => 'encrypted',
        'wc_consumer_secret' => 'encrypted',
        'wc_webhook_secret' => 'encrypted',
        'ff_api_key' => 'encrypted',
        'ff_username' => 'encrypted',
        'ff_app_password' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'last_sync_at' => 'datetime',
        'last_webhook_at' => 'datetime',
    ];

    public function wcOrders()
    {
        return $this->hasMany(WcOrder::class);
    }

    public function ffSubmissions()
    {
        return $this->hasMany(FfSubmission::class);
    }

    public function webhookEvents()
    {
        return $this->hasMany(WebhookEvent::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include websites owned by a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Check if the website belongs to a specific user.
     */
    public function belongsToUser($userId): bool
    {
        return $this->user_id === $userId;
    }
}
