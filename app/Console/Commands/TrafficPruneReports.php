<?php

namespace App\Console\Commands;

use App\Models\TrafficReport;
use Illuminate\Console\Command;

class TrafficPruneReports extends Command
{
    protected $signature = 'traffic:prune-reports';

    protected $description = 'Remove up to 1000 traffic report snapshots unused for thirty days';

    public function handle(): int
    {
        $ids = TrafficReport::where('updated_at', '<', now()->subDays(30))->orderBy('updated_at')->limit(1000)->pluck('id');
        $count = TrafficReport::whereIn('id', $ids)->where('updated_at', '<', now()->subDays(30))->delete();
        $this->info("Removed {$count} expired traffic report snapshot(s).");

        return self::SUCCESS;
    }
}
