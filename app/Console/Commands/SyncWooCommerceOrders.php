<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\WooCommerceOrderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncWooCommerceOrders extends Command
{
    protected $signature = 'orders:sync-woocommerce
                            {--website= : Sync a specific website by ID (optional)}
                            {--per-page=50 : Orders per API page (default: 50)}';

    protected $description = 'Incrementally sync missing WooCommerce orders from all active websites';

    public function __construct(private WooCommerceOrderSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $perPage   = (int) $this->option('per-page');
        $websiteId = $this->option('website');

        // ── Single website mode ───────────────────────────────────────────────
        if ($websiteId) {
            $website = Website::where('status', 'active')->find($websiteId);

            if (! $website) {
                $this->error("Website #{$websiteId} not found or not active.");
                return Command::FAILURE;
            }

            $this->info("Syncing: {$website->name}…");
            $result = $this->syncService->syncForWebsite($website, $perPage);

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
        $failed      = 0;

        foreach ($websites as $website) {
            $this->line("  → {$website->name}");

            $result = $this->syncService->syncForWebsite($website, $perPage);

            if ($result['status'] === 'success') {
                $icon    = $result['synced'] > 0 ? '✓' : '–';
                $this->line("    {$icon} {$result['message']}");
                $totalSynced += $result['synced'];
            } else {
                $this->error("    ✗ {$result['message']}");
                $failed++;

                Log::warning('[WooSync] Scheduled sync failed for website', [
                    'website_id'   => $website->id,
                    'website_name' => $website->name,
                    'error'        => $result['message'],
                ]);
            }
        }

        $this->newLine();

        if ($failed === 0) {
            $this->info(
                $totalSynced > 0
                    ? "Done — {$totalSynced} new order(s) synced across {$websites->count()} website(s)."
                    : "Done — all websites already up to date."
            );
        } else {
            $this->warn("Done — {$totalSynced} new order(s) synced. {$failed} website(s) failed.");
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
