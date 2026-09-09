<?php

namespace App\Jobs;

use App\Models\TrafficReport;
use App\Services\TrafficReporting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TrafficRefreshReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $reportId, public string $runKey)
    {
        $this->onConnection('traffic')->onQueue('traffic');
    }

    public function handle(TrafficReporting $reporting): void
    {
        $reporting->refresh($this->reportId, $this->runKey);
    }

    public function failed(?Throwable $exception): void
    {
        TrafficReport::whereKey($this->reportId)->where('run_key', $this->runKey)
            ->whereIn('status', ['queued', 'running'])->update([
                'status' => 'failed', 'error' => 'The report refresh did not finish. Please retry in a few minutes.',
                'refreshed_at' => now(), 'run_key' => null, 'payload' => null,
            ]);
    }
}
