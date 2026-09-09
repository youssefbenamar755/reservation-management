<?php

namespace App\Jobs;

use App\Models\SeoReport;
use App\Services\SeoReporting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SeoRefreshReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $reportId, public string $runKey)
    {
        $this->onConnection('traffic')->onQueue('traffic');
    }

    public function handle(SeoReporting $reporting): void
    {
        $reporting->refresh($this->reportId, $this->runKey);
    }

    public function failed(?Throwable $exception): void
    {
        SeoReport::whereKey($this->reportId)->where('run_key', $this->runKey)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error' => 'The SEO analysis did not finish. Please retry in a few minutes.',
            'refreshed_at' => now(), 'run_key' => null, 'payload' => null,
        ]);
    }
}
