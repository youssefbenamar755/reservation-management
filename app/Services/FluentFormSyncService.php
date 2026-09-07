<?php

namespace App\Services;

use App\Exceptions\FluentSyncException;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;

class FluentFormSyncService
{
    public function __construct(private FluentFormSubmissionService $submissions) {}

    public function progress(Website $website, int $formId): ?array
    {
        return Cache::get($this->key($website, $formId));
    }

    public function syncNextPage(Website $website, int $formId, int $perPage = 100, int $startPage = 1): array
    {
        if ($formId < 1 || $perPage < 1 || $perPage > 100 || $startPage < 1) {
            throw new FluentSyncException('Invalid Fluent Forms pagination parameters.');
        }
        $key = $this->key($website, $formId);
        $lock = Cache::lock($key.':lock', 60);
        if (! $lock->get()) {
            throw new FluentSyncException('A sync for this form is already running. Try again shortly.');
        }
        try {
            $state = Cache::get($key) ?? ['page' => $startPage, 'per_page' => $perPage, 'synced' => 0, 'fingerprint' => null];
            // Persist before fetching. Errors retain this page; successful pages
            // advance only after all their records have committed.
            Cache::put($key, $state, now()->addDay());
            // Keep the scan's original page size even when another caller resumes it.
            $result = $this->submissions->syncPage($website, $formId, $state['page'], $state['per_page']);
            if (($result['entries_in_batch'] ?? 0) > 0 && isset($result['page_fingerprint']) && $result['page_fingerprint'] === $state['fingerprint']) {
                throw new FluentSyncException('Fluent Forms repeated a page instead of advancing. The import is incomplete.');
            }
            $state['synced'] += $result['count'];
            if ($result['has_more']) {
                $state['page']++;
                $state['fingerprint'] = $result['page_fingerprint'] ?? null;
                Cache::put($key, $state, now()->addDay());

                return ['status' => 'partial', 'message' => 'Page imported. More submissions remain.', 'synced' => $result['count'], 'updated' => 0, 'next_page' => $state['page']];
            }
            $website->update(['last_sync_at' => now()]);
            Cache::forget($key);

            return ['status' => 'success', 'message' => "Fluent Forms sync completed. Imported {$state['synced']} new submission(s).", 'synced' => $result['count'], 'updated' => 0, 'next_page' => null];
        } finally {
            $lock->release();
        }
    }

    private function key(Website $website, int $formId): string
    {
        return "fluent_sync_v1:{$website->id}:{$formId}";
    }
}
