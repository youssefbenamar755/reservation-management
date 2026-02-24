<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\FluentFormSchemaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Syncs a single Fluent Form's schema (field definitions) from the WordPress API.
 * Dispatched asynchronously when a webhook arrives for a form with no cached schema.
 */
class SyncFormSchema implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;

    public function __construct(
        protected Website $website,
        protected int $formId
    ) {}

    public function handle(FluentFormSchemaService $schemaService): void
    {
        Log::info('SyncFormSchema job: starting schema sync', [
            'website_id' => $this->website->id,
            'form_id'    => $this->formId,
        ]);

        $result = $schemaService->syncFormSchema($this->website, $this->formId);

        if ($result) {
            Log::info('SyncFormSchema job: schema synced successfully', [
                'website_id'   => $this->website->id,
                'form_id'      => $this->formId,
                'fields_count' => count($result->fields ?? []),
            ]);
        } else {
            Log::warning('SyncFormSchema job: schema sync returned null', [
                'website_id' => $this->website->id,
                'form_id'    => $this->formId,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncFormSchema job failed', [
            'website_id' => $this->website->id,
            'form_id'    => $this->formId,
            'error'      => $e->getMessage(),
        ]);
    }
}
