<?php

namespace App\Console\Commands;

use App\Events\WcOrdersSynced;
use App\Models\Website;
use App\Services\WooCommerceOrderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWooCommerceOrders extends Command
{
    protected $signature = 'orders:sync-woocommerce
                            {--website= : Sync a specific website by ID (optional)}
                            {--per-page=50 : Orders per API page (default: 50)}
                            {--full : Reconcile all historical orders as well as recent changes}';

    protected $description = 'Reconcile WooCommerce orders changed since the last successful sync';

    public function __construct(private WooCommerceOrderSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $perPage = (int) $this->option('per-page');
        $websiteId = $this->option('website');

        // ── Single website mode ───────────────────────────────────────────────
        if ($websiteId) {
            $website = Website::where('status', 'active')->find($websiteId);

            if (! $website) {
                $this->error("Website #{$websiteId} not found or not active.");

                return Command::FAILURE;
            }

            $this->info("Syncing: {$website->name}…");
            $result = $this->syncWebsite($website, $perPage);

            if ($result['status'] === 'success') {
                $this->info("✓ {$result['message']}");

                return Command::SUCCESS;
            }

            $this->error("✗ {$result['message']}");

            return Command::FAILURE;
        }

        // ── All active websites mode ──────────────────────────────────────────
        $websites = Website::where('status', 'active')->get();

        if ($websites->isEmpty()) {
            $this->warn('No active websites found. Nothing to sync.');

            return Command::SUCCESS;
        }

        $this->info("Syncing {$websites->count()} active website(s)…");

        $totalSynced = 0;
        $totalUpdated = 0;
        $failed = 0;

        foreach ($websites as $website) {
            $this->line("  → {$website->name}");

            $result = $this->syncWebsite($website, $perPage);
            $totalSynced += $result['synced'];
            $totalUpdated += $result['updated'];

            if ($result['status'] === 'success') {
                $icon = $result['synced'] + $result['updated'] > 0 ? '✓' : '–';
                $this->line("    {$icon} {$result['message']}");
            } else {
                $this->error("    ✗ {$result['message']}");
                $failed++;

                Log::warning('[WooSync] Scheduled sync failed for website', [
                    'website_id' => $website->id,
                    'website_name' => $website->name,
                    'error' => $result['message'],
                ]);
            }
        }

        $this->newLine();

        if ($failed === 0) {
            $this->info("Done — {$totalSynced} new order(s) synced; {$totalUpdated} existing order(s) updated across {$websites->count()} website(s).");
        } else {
            $this->warn("Done — {$totalSynced} new order(s) synced; {$totalUpdated} existing order(s) updated. {$failed} website(s) failed.");
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function syncWebsite(Website $website, int $perPage): array
    {
        $synced = 0;
        $updated = 0;
        do {
            $result = $this->syncService->syncForWebsite($website, $perPage, full: (bool) $this->option('full'));
            $synced += $result['synced'];
            $updated += $result['updated'];
        } while ($result['status'] === 'partial');

        $result['synced'] = $synced;
        $result['updated'] = $updated;
        if ($result['status'] === 'success') {
            $result['message'] = "Synced {$synced} new WooCommerce order(s); updated {$updated} existing order(s).";
        }

        // One refresh covers every persisted page, even if a later page failed.
        if ($synced + $updated > 0) {
            try {
                Event::dispatch(new WcOrdersSynced($website));
            } catch (Throwable $e) {
                Log::warning('[WooSync] Failed to broadcast order changes', [
                    'website_id' => $website->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}
