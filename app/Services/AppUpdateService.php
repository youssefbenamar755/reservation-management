<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class AppUpdateService
{
    /**
     * Get the code version from config.
     */
    public function getCodeVersion(): array
    {
        return config('app_version', [
            'name' => 'Application',
            'version' => '0.0.0',
            'build' => date('Y-m-d'),
        ]);
    }

    /**
     * Get the installed version from database.
     */
    public function getInstalledVersion(): ?array
    {
        return Setting::get('app_version');
    }

    /**
     * Check if an update is required.
     */
    public function isUpdateRequired(): bool
    {
        $codeVersion = $this->getCodeVersion();
        $installedVersion = $this->getInstalledVersion();

        // If no installed version exists, update is required (initial setup)
        if (!$installedVersion) {
            return true;
        }

        $codeVersionString = $codeVersion['version'] ?? '0.0.0';
        $installedVersionString = $installedVersion['version'] ?? '0.0.0';

        // Use version_compare to check if code version is newer
        return version_compare($codeVersionString, $installedVersionString, '>');
    }

    /**
     * Get update status information.
     */
    public function getUpdateStatus(): array
    {
        $codeVersion = $this->getCodeVersion();
        $installedVersion = $this->getInstalledVersion();

        return [
            'app_name' => $codeVersion['name'] ?? 'Application',
            'code_version' => $codeVersion['version'] ?? '0.0.0',
            'code_build' => $codeVersion['build'] ?? date('Y-m-d'),
            'installed_version' => $installedVersion['version'] ?? null,
            'installed_build' => $installedVersion['build'] ?? null,
            'update_required' => $this->isUpdateRequired(),
            'status' => $this->isUpdateRequired() ? 'Update required' : 'Up to date',
        ];
    }

    /**
     * Update the installed version to match code version.
     */
    public function updateInstalledVersion(): void
    {
        $codeVersion = $this->getCodeVersion();
        Setting::set('app_version', $codeVersion);

        Log::info('App version updated', [
            'version' => $codeVersion['version'] ?? '0.0.0',
            'build' => $codeVersion['build'] ?? date('Y-m-d'),
        ]);
    }
}

