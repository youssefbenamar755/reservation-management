<?php

namespace App\Observers;

use App\Models\FfSubmission;
use App\Models\WcOrder;
use App\Services\MarketingContactDiscovery;

class MarketingSourceObserver
{
    public function saved(FfSubmission|WcOrder $source): void
    {
        if ($source instanceof WcOrder) {
            if ($source->wasRecentlyCreated || $source->wasChanged(['website_id', 'customer_email', 'customer_name'])) {
                app(MarketingContactDiscovery::class)->order($source);
            }
        } elseif ($source->wasRecentlyCreated || $source->wasChanged(['website_id', 'email', 'payload', 'form_id'])) {
            app(MarketingContactDiscovery::class)->submission($source);
        }
    }
}
