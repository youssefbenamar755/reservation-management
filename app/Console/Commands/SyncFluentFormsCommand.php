<?php

namespace App\Console\Commands;

use App\Exceptions\FluentSyncException;
use App\Jobs\SyncFluentSubmissions;
use App\Models\Website;
use Illuminate\Console\Command;

class SyncFluentFormsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fluent:sync 
                            {website : The ID or slug of the website to sync}
                            {form_id : The form ID to sync}
                            {--per-page=100 : Number of submissions per page}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Fluent Forms submissions for a specific form from a connected website via REST API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $websiteIdentifier = $this->argument('website');
        $formId = (int) $this->argument('form_id');
        $perPage = (int) $this->option('per-page');
        if ($formId < 1 || $perPage < 1 || $perPage > 100) {
            $this->error('Form ID must be positive and per-page must be between 1 and 100.');

            return Command::FAILURE;
        }

        // Try to find website by ID first, then by slug
        $website = Website::where('id', $websiteIdentifier)
            ->orWhere('slug', $websiteIdentifier)
            ->first();

        if (! $website) {
            $this->error("Website not found: {$websiteIdentifier}");

            return Command::FAILURE;
        }

        // Validate credentials
        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            $this->error("Fluent Forms credentials are not configured for website: {$website->name}");
            $this->info('Please configure ff_username and ff_app_password in the website settings.');

            return Command::FAILURE;
        }

        if ($website->status !== 'active') {
            $this->error('Website is not active.');

            return Command::FAILURE;
        }

        $this->info("Starting Fluent Forms sync for: {$website->name} (ID: {$website->id})");
        $this->info("Form ID: {$formId}");
        $this->info("Using per_page: {$perPage}");

        try {
            // Dispatch the job (if queue is configured) or run synchronously
            if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
                $this->info('Running synchronously...');
                $job = new SyncFluentSubmissions($website->id, $formId, $perPage);
                do {
                    $result = $job->handle();
                } while ($result['status'] === 'partial');
                $this->info('Sync completed successfully!');
            } else {
                $this->info('Queuing sync job...');
                SyncFluentSubmissions::dispatch($website->id, $formId, $perPage);
                $this->info('Sync job queued. Check your queue worker logs for progress.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof FluentSyncException ? 'Sync failed: '.$e->getMessage() : 'Sync failed. Retry to resume the saved page.');

            return Command::FAILURE;
        }
    }
}
