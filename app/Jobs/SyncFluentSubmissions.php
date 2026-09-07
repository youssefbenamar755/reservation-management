<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\FluentFormSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFluentSubmissions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public array $backoff = [15, 60, 180];

    public function __construct(
        public int $websiteId,
        public int $formId,
        public ?int $perPage = 100,
        public int $startPage = 1,
    ) {}

    public function handle(?FluentFormSyncService $service = null): array
    {
        $service ??= app(FluentFormSyncService::class);
        $website = Website::findOrFail($this->websiteId);
        $result = $service->syncNextPage($website, $this->formId, $this->perPage ?? 100, $this->startPage);
        // With the sync driver the caller continues explicitly; recursively
        // dispatching another job would still block the same HTTP request.
        $connection = $this->connection ?? config('queue.default');
        if ($result['status'] === 'partial' && config("queue.connections.$connection.driver") !== 'sync') {
            self::dispatch($this->websiteId, $this->formId, $this->perPage)
                ->onConnection($connection)->onQueue($this->queue);
        }

        return $result;
    }

    public function failed(Throwable $e): void
    {
        // No payload, credentials, response body, or customer data in failure logs.
        Log::error('Fluent Forms import failed; saved page can be retried.', [
            'website_id' => $this->websiteId, 'form_id' => $this->formId,
        ]);
    }
}
