<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\MarketingContactDiscovery;
use Illuminate\Console\Command;

class DiscoverMarketingContacts extends Command
{
    protected $signature = 'marketing:discover {--website= : Restrict to a website ID}';

    protected $description = 'Discover contacts from locally synced orders and Fluent Forms entries without subscribing them';

    public function handle(MarketingContactDiscovery $discovery): int
    {
        if ($this->option('website') !== null && (! ctype_digit((string) $this->option('website')) || (int) $this->option('website') < 1)) {
            $this->error('Use a positive website ID.');

            return self::FAILURE;
        }
        $sites = Website::when($this->option('website'), fn ($q) => $q->whereKey($this->option('website')))->orderBy('id')->get(['id']);
        if ($sites->isEmpty()) {
            $this->error('No matching websites.');

            return self::FAILURE;
        }
        foreach ($sites as $site) {
            $added = $discovery->discover($site->id);
            $this->info("Website {$site->id}: {$added} new contacts. Existing preferences preserved.");
        }

        return self::SUCCESS;
    }
}
