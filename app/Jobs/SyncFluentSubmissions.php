<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\FfSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFluentSubmissions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $websiteId,
        public int $formId,
        public ?int $perPage = 100
    ) {}

    public function handle(): void
    {
        $website = Website::findOrFail($this->websiteId);

        // Validate credentials
        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            throw new \Exception('Fluent Forms authentication credentials are missing for website ID: ' . $this->websiteId);
        }

        if ($website->status !== 'active') {
            throw new \Exception('Website is not active. Cannot sync submissions.');
        }

        $baseUrl = rtrim($website->base_url, '/');
        $username = $website->ff_username;
        $password = $website->ff_app_password;

        // Verify form exists before syncing
        $formsEndpoint = "{$baseUrl}/wp-json/fluentform/v1/forms";
        
        try {
            $formsResponse = Http::timeout(30)
                ->withBasicAuth($username, $password)
                ->acceptJson()
                ->get($formsEndpoint);

            if (!$formsResponse->successful()) {
                $status = $formsResponse->status();
                $message = $formsResponse->json()['message'] ?? $formsResponse->body();
                throw new \Exception("Failed to fetch forms (HTTP {$status}): {$message}");
            }

            $forms = $formsResponse->json();
            
            if (!is_array($forms)) {
                Log::error('Fluent Forms API returned non-array response', [
                    'website_id' => $website->id,
                    'response' => $forms,
                ]);
                throw new \Exception('Unexpected response format when fetching forms. Expected array, got: ' . gettype($forms));
            }

            // Verify the form exists
            $formExists = false;
            foreach ($forms as $form) {
                $checkFormId = null;
                if (is_numeric($form)) {
                    $checkFormId = (int) $form;
                } elseif (is_array($form) && isset($form[0]) && is_array($form[0])) {
                    foreach ($form as $nestedForm) {
                        $nestedFormId = data_get($nestedForm, 'id');
                        if ($nestedFormId && (int) $nestedFormId === $this->formId) {
                            $formExists = true;
                            break;
                        }
                    }
                } elseif (is_array($form) || is_object($form)) {
                    $checkFormId = data_get($form, 'id');
                }
                
                if ($checkFormId && (int) $checkFormId === $this->formId) {
                    $formExists = true;
                    break;
                }
            }
            
            if (!$formExists) {
                throw new \Exception("Form ID {$this->formId} not found for this website.");
            }

            // Sync only the specified form
            $totalSynced = $this->syncSubmissionsForForm(
                $website,
                $baseUrl,
                $username,
                $password,
                $this->formId
            );

            // Update last sync time
            $website->update(['last_sync_at' => now()]);

            Log::info("Fluent Forms sync completed", [
                'website_id' => $website->id,
                'form_id' => $this->formId,
                'total_submissions_synced' => $totalSynced,
            ]);

        } catch (\Throwable $e) {
            Log::error("Fluent Forms sync failed", [
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync submissions for a specific form
     */
    private function syncSubmissionsForForm(
        Website $website,
        string $baseUrl,
        string $username,
        string $password,
        int $formId
    ): int {
        $perPage = $this->perPage ?? 100;
        $page = 1;
        $synced = 0;
        $hasMore = true;

        while ($hasMore) {
            // Try different endpoint variations
            $endpoints = [
                "{$baseUrl}/wp-json/fluentform/v1/forms/{$formId}/submissions",
                "{$baseUrl}/wp-json/fluentform/v1/forms/{$formId}/entries",
                "{$baseUrl}/wp-json/fluentform/v1/submissions?form_id={$formId}",
            ];
            
            $response = null;
            $data = null;
            $usedEndpoint = null;
            
            // Try each endpoint until one works
            foreach ($endpoints as $endpoint) {
                try {
                    $response = Http::timeout(30)
                        ->withBasicAuth($username, $password)
                        ->acceptJson()
                        ->get($endpoint, [
                            'per_page' => $perPage,
                            'page' => $page,
                        ]);

                    if ($response->successful()) {
                        $usedEndpoint = $endpoint;
                        $data = $response->json();
                        break; // Found working endpoint
                    }
                } catch (\Throwable $e) {
                    continue; // Try next endpoint
                }
            }
            
            try {
                if (!$response || !$response->successful()) {
                    $status = $response ? $response->status() : 'no response';
                    Log::warning("Failed to fetch submissions for form {$formId} (HTTP {$status})", [
                        'website_id' => $website->id,
                        'form_id' => $formId,
                        'page' => $page,
                        'tried_endpoints' => $endpoints,
                    ]);
                    break;
                }

                // Log the response structure for debugging
                Log::debug("Fluent Forms API response for form {$formId}", [
                    'website_id' => $website->id,
                    'form_id' => $formId,
                    'endpoint' => $usedEndpoint,
                    'response_type' => gettype($data),
                    'response_keys' => is_array($data) ? array_keys($data) : 'not_array',
                    'response_sample' => is_array($data) ? array_slice($data, 0, 1) : $data,
                ]);
                
                // Handle different response formats
                $submissions = [];
                
                if (is_array($data)) {
                    // Check for common response structures
                    if (isset($data['data']) && is_array($data['data'])) {
                        $submissions = $data['data'];
                    } elseif (isset($data['submissions']) && is_array($data['submissions'])) {
                        $submissions = $data['submissions'];
                    } elseif (isset($data['entries']) && is_array($data['entries'])) {
                        $submissions = $data['entries'];
                    } else {
                        // Assume the array itself contains submissions
                        $submissions = $data;
                    }
                } elseif (is_object($data)) {
                    // Handle object response
                    if (isset($data->data) && is_array($data->data)) {
                        $submissions = $data->data;
                    } elseif (isset($data->submissions) && is_array($data->submissions)) {
                        $submissions = $data->submissions;
                    } elseif (isset($data->entries) && is_array($data->entries)) {
                        $submissions = $data->entries;
                    }
                }

                if (!is_array($submissions) || empty($submissions)) {
                    Log::info("No submissions found for form {$formId} (page {$page})", [
                        'website_id' => $website->id,
                        'form_id' => $formId,
                        'response_data' => $data,
                    ]);
                    break; // No more submissions
                }

                Log::info("Processing submissions for form {$formId}", [
                    'website_id' => $website->id,
                    'form_id' => $formId,
                    'page' => $page,
                    'submissions_count' => count($submissions),
                ]);

                // Process each submission
                foreach ($submissions as $submission) {
                    // Try multiple possible ID fields
                    $entryId = data_get($submission, 'id') 
                        ?? data_get($submission, 'entry_id')
                        ?? data_get($submission, 'entryId')
                        ?? data_get($submission, 'submission_id');
                    
                    if (!$entryId) {
                        Log::warning("Skipping submission without entry_id", [
                            'website_id' => $website->id,
                            'form_id' => $formId,
                            'submission_keys' => is_array($submission) ? array_keys($submission) : 'not_array',
                            'submission_sample' => is_array($submission) ? array_slice($submission, 0, 5) : $submission,
                        ]);
                        continue;
                    }

                    // Extract email defensively
                    $email = $this->extractEmail($submission);

                    // Parse created_at
                    $createdAt = data_get($submission, 'created_at') 
                        ?? data_get($submission, 'date_created') 
                        ?? now();

                    FfSubmission::updateOrCreate(
                        [
                            'website_id' => $website->id,
                            'form_id' => $formId,
                            'entry_id' => $entryId,
                        ],
                        [
                            'email' => $email,
                            'created_at_wp' => $createdAt,
                            'payload' => $submission,
                        ]
                    );

                    $synced++;
                }

                // Check if there are more pages
                // Fluent Forms API might return total pages or we check if we got fewer than per_page
                $hasMore = count($submissions) >= $perPage;
                $page++;

            } catch (\Throwable $e) {
                Log::error("Error syncing submissions for form {$formId}", [
                    'website_id' => $website->id,
                    'form_id' => $formId,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

        }

        return $synced;
    }

    /**
     * Extract email from submission payload defensively
     * Tries common field names and nested structures
     */
    private function extractEmail(array $submission): ?string
    {
        // Common field names in Fluent Forms
        $emailFields = [
            'email',
            'Email',
            'EMAIL',
            'email_address',
            'emailAddress',
            'user_email',
            'contact_email',
        ];

        // Check direct fields
        foreach ($emailFields as $field) {
            if (!empty($submission[$field])) {
                $email = filter_var($submission[$field], FILTER_VALIDATE_EMAIL);
                if ($email) {
                    return $email;
                }
            }
        }

        // Check in response field (if present)
        if (isset($submission['response']) && is_array($submission['response'])) {
            foreach ($emailFields as $field) {
                $value = data_get($submission['response'], $field);
                if (!empty($value)) {
                    $email = filter_var($value, FILTER_VALIDATE_EMAIL);
                    if ($email) {
                        return $email;
                    }
                }
            }
        }

        // Check in input fields (common Fluent Forms structure)
        if (isset($submission['inputs']) && is_array($submission['inputs'])) {
            foreach ($submission['inputs'] as $input) {
                if (isset($input['name']) && in_array(strtolower($input['name']), array_map('strtolower', $emailFields))) {
                    if (!empty($input['value'])) {
                        $email = filter_var($input['value'], FILTER_VALIDATE_EMAIL);
                        if ($email) {
                            return $email;
                        }
                    }
                }
            }
        }

        return null;
    }

    public function failed(Throwable $e): void
    {
        Log::error("SyncFluentSubmissions job failed", [
            'website_id' => $this->websiteId,
            'error' => $e->getMessage(),
        ]);
    }
}
