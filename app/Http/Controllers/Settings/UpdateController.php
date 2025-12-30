<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AppUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class UpdateController extends Controller
{
    protected AppUpdateService $updateService;

    public function __construct(AppUpdateService $updateService)
    {
        $this->updateService = $updateService;
    }

    /**
     * Display the updates page.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/Updates', [
            'updateStatus' => $this->updateService->getUpdateStatus(),
        ]);
    }

    /**
     * Run the update process (migrations, cache clear, version update).
     * ADMIN ONLY.
     */
    public function run(Request $request): RedirectResponse
    {
        try {
            // Run migrations (migrations have their own transaction management)
            Artisan::call('migrate', ['--force' => true]);
            $migrationOutput = Artisan::output();

            // Clear all caches
            Artisan::call('optimize:clear');

            // Update installed version to match code version
            $this->updateService->updateInstalledVersion();

            Log::info('Application update completed successfully', [
                'user_id' => $request->user()->id,
                'version' => $this->updateService->getCodeVersion()['version'] ?? 'unknown',
            ]);

            return redirect()->route('updates.index')->with('success', 'Update completed successfully. All migrations have been run and caches have been cleared.');
        } catch (\Exception $e) {
            Log::error('Application update failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('updates.index')->with('error', 'Update failed: ' . $e->getMessage());
        }
    }
}
