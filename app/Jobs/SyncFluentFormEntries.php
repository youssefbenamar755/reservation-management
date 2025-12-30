<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\FluentFormSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncFluentFormEntries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout

    protected $website;
    protected $formId;
    protected $startPage;

    /**
     * Create a new job instance.
     */
    public function __construct(Website $website, int $formId, int $startPage = 1)
    {
        $this->website = $website;
        $this->formId = $formId;
        $this->startPage = $startPage;
    }

    /**
     * Execute the job.
     */
    public function handle(FluentFormSubmissionService $service): void
    {
        $page = $this->startPage;
        $hasMore = true;
        $totalSynced = 0;

        Log::info('Starting background sync for form entries', [
            'website_id' => $this->website->id,
            'form_id' => $this->formId,
            'start_page' => $page
        ]);

        while ($hasMore) {
            $result = $service->syncPage($this->website, $this->formId, $page);
            
            $syncedCount = $result['count'];
            $entriesInBatch = $result['entries_in_batch'];
            $hasMore = $result['has_more'];
            $matchedCount = $result['matched_count'] ?? $entriesInBatch;
            
            $totalSynced += $syncedCount;

            // OPTIMIZATION: If we found no NEW entries in a full batch, we assume we've reached history we already have.
            // This assumes entries are returned in reverse chronological order (newest first).
            // BUT we only stop if we actually saw VALID entries for this form.
            if ($syncedCount === 0 && $entriesInBatch > 0) {
                if ($matchedCount > 0) {
                    Log::info('Stopped sync early as no new entries found in batch', [
                        'website_id' => $this->website->id,
                        'form_id' => $this->formId,
                        'page' => $page
                    ]);
                    break;
                } else {
                    // If matchedCount is 0, it means the whole batch was mismatched (e.g. wrong form ID).
                    // We should continue to find the real entries.
                     Log::debug('Batch full of mismatched entries, continuing to next page', [
                        'website_id' => $this->website->id,
                        'page' => $page
                    ]);
                }
            }

            $page++;
            
            // Safety break to prevent infinite loops if something goes wrong with pagination
            if ($page > 1000) {
                Log::warning('Stopped sync due to excessive page count', [
                    'website_id' => $this->website->id,
                    'form_id' => $this->formId,
                ]);
                break;
            }
        }

        Log::info('Finished background sync for form entries', [
            'website_id' => $this->website->id,
            'form_id' => $this->formId,
            'total_synced' => $totalSynced
        ]);
    }
}
