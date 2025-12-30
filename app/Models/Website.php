<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    protected $fillable = [
        'name','slug','base_url',
        'wc_consumer_key','wc_consumer_secret',
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
        'ff_api_key',
        'ff_username',
        'ff_app_password',
        'webhook_secret',
    ];

    protected $casts = [
        'wc_consumer_key' => 'encrypted',
        'wc_consumer_secret' => 'encrypted',
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
}
