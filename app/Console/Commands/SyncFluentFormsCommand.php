<?php

namespace App\Console\Commands;

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

        // Try to find website by ID first, then by slug
        $website = Website::where('id', $websiteIdentifier)
            ->orWhere('slug', $websiteIdentifier)
            ->first();

        if (!$website) {
            $this->error("Website not found: {$websiteIdentifier}");
            return Command::FAILURE;
        }

        // Validate credentials
        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            $this->error("Fluent Forms credentials are not configured for website: {$website->name}");
            $this->info("Please configure ff_username and ff_app_password in the website settings.");
            return Command::FAILURE;
        }

        if ($website->status !== 'active') {
            $this->warn("Website '{$website->name}' is not active. Syncing anyway...");
        }

        $this->info("Starting Fluent Forms sync for: {$website->name} (ID: {$website->id})");
        $this->info("Form ID: {$formId}");
        $this->info("Using per_page: {$perPage}");

        try {
            // Dispatch the job (if queue is configured) or run synchronously
            if (config('queue.default') === 'sync') {
                $this->info("Running synchronously...");
                $job = new SyncFluentSubmissions($website->id, $formId, $perPage);
                $job->handle();
                $this->info("Sync completed successfully!");
            } else {
                $this->info("Queuing sync job...");
                SyncFluentSubmissions::dispatch($website->id, $formId, $perPage);
                $this->info("Sync job queued. Check your queue worker logs for progress.");
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Sync failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
