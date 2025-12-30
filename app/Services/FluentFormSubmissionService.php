<?php

namespace App\Services;

use App\Models\FfSubmission;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FluentFormSubmissionService
{
    /**
     * Sync entries for a specific page.
     * Returns an array with 'count' (synced count) and 'has_more' (boolean).
     */
    public function syncPage(Website $website, int $formId, int $page = 1, int $perPage = 100): array
    {
        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            Log::warning('Fluent Forms credentials not configured for syncing entries', [
                'website_id' => $website->id,
                'form_id' => $formId,
            ]);
            return ['count' => 0, 'has_more' => false, 'entries_in_batch' => 0];
        }

        $baseUrl = rtrim($website->base_url, '/');
        $username = $website->ff_username;
        $password = $website->ff_app_password;

        $response = null;
        $data = null;
        
        $endpoints = [
            "{$baseUrl}/wp-json/fluentform/v1/forms/{$formId}/submissions",
            "{$baseUrl}/wp-json/fluentform/v1/forms/{$formId}/entries",
            "{$baseUrl}/wp-json/fluentform/v1/submissions?form_id={$formId}",
        ];

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::timeout(60)
                    ->withBasicAuth($username, $password)
                    ->acceptJson()
                    ->get($endpoint, [
                        'per_page' => $perPage,
                        'page' => $page,
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (!$response || !$response->successful()) {
            return ['count' => 0, 'has_more' => false, 'entries_in_batch' => 0];
        }

        $submissions = $this->parseSubmissionsFromResponse($data);
        
        if (empty($submissions)) {
            return ['count' => 0, 'has_more' => false, 'entries_in_batch' => 0];
        }

        $newEntriesCount = 0;
        $matchedCount = 0;
        $existingEntryIds = FfSubmission::where('website_id', $website->id)
            ->where('form_id', $formId)
            ->whereIn('entry_id', collect($submissions)->pluck('id')->filter()->toArray()) // Optimization: Only check IDs in this batch
            ->pluck('entry_id')
            ->toArray();

        foreach ($submissions as $submission) {
             // Try multiple possible ID fields
             $entryId = data_get($submission, 'id') 
             ?? data_get($submission, 'entry_id')
             ?? data_get($submission, 'entryId')
             ?? data_get($submission, 'submission_id');
         
            if (!$entryId) {
                continue;
            }

            // STRICT SAFETY CHECK: Verify that the entry belongs to the requested form
            // We must find the form_id in the submission and it must match what we requested.
            $entryFormId = data_get($submission, 'form_id');
            
            if (!$entryFormId || (int)$entryFormId !== $formId) {
                // Log only in debug to avoid noise, but this indicates a potential API issue or filtering failure
                // Log::debug("Skipping entry {$entryId} because form_id mismatch. Found: " . ($entryFormId ?? 'null') . ", Expected: {$formId}");
                continue;
            }
            
            $matchedCount++;

            if (in_array($entryId, $existingEntryIds)) {
                continue;
            }

            $email = $this->extractEmailFromSubmission($submission);
            $createdAt = data_get($submission, 'created_at') 
                ?? data_get($submission, 'date_created') 
                ?? now();

            FfSubmission::create([
                'website_id' => $website->id,
                'form_id' => $formId,
                'entry_id' => $entryId,
                'email' => $email,
                'created_at_wp' => $createdAt,
                'payload' => $submission,
            ]);

            $newEntriesCount++;
        }

        $hasMore = count($submissions) >= $perPage;

        return [
            'count' => $newEntriesCount, 
            'has_more' => $hasMore,
            'entries_in_batch' => count($submissions),
            'matched_count' => $matchedCount,
        ];
    }

    private function parseSubmissionsFromResponse($data): array
    {
        if (is_array($data)) {
            if (isset($data['data']) && is_array($data['data'])) {
                return $data['data'];
            } elseif (isset($data['submissions']) && is_array($data['submissions'])) {
                return $data['submissions'];
            } elseif (isset($data['entries']) && is_array($data['entries'])) {
                return $data['entries'];
            }
             return $data;
        } elseif (is_object($data)) {
            if (isset($data->data) && is_array($data->data)) {
                return $data->data;
            } elseif (isset($data->submissions) && is_array($data->submissions)) {
                return $data->submissions;
            } elseif (isset($data->entries) && is_array($data->entries)) {
                return $data->entries;
            }
        }
        return [];
    }

    private function extractEmailFromSubmission(array $submission): ?string
    {
        $emailFields = [
            'email', 'Email', 'EMAIL', 'email_address', 'emailAddress', 'user_email', 'contact_email',
        ];

        foreach ($emailFields as $field) {
            if (!empty($submission[$field])) {
                $email = filter_var($submission[$field], FILTER_VALIDATE_EMAIL);
                if ($email) return $email;
            }
        }

        if (isset($submission['response']) && is_array($submission['response'])) {
            foreach ($emailFields as $field) {
                $value = data_get($submission['response'], $field);
                if (!empty($value)) {
                    $email = filter_var($value, FILTER_VALIDATE_EMAIL);
                    if ($email) return $email;
                }
            }
        }

        if (isset($submission['inputs']) && is_array($submission['inputs'])) {
            foreach ($submission['inputs'] as $input) {
                if (isset($input['name']) && in_array(strtolower($input['name']), array_map('strtolower', $emailFields))) {
                    if (!empty($input['value'])) {
                        $email = filter_var($input['value'], FILTER_VALIDATE_EMAIL);
                        if ($email) return $email;
                    }
                }
            }
        }

        return null;
    }
}
