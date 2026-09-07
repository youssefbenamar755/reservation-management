<?php

namespace App\Services;

use App\Exceptions\FluentSyncException;
use App\Models\FfSubmission;
use App\Models\Website;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FluentFormSubmissionService
{
    /** Fetch one page. Errors are never represented as an exhausted history. */
    public function syncPage(Website $website, int $formId, int $page = 1, int $perPage = 100): array
    {
        if (! $website->ff_username || ! $website->ff_app_password) {
            throw new FluentSyncException('Fluent Forms credentials are missing.');
        }
        if ($website->status !== 'active') {
            throw new FluentSyncException('Website is not active.');
        }
        if ($formId < 1 || $page < 1 || $page > 10000 || $perPage < 1 || $perPage > 100) {
            throw new FluentSyncException('Invalid Fluent Forms pagination parameters.');
        }

        $base = rtrim($website->base_url, '/').'/wp-json/fluentform/v1';
        $endpoints = ["$base/forms/$formId/submissions", "$base/forms/$formId/entries", "$base/submissions"];
        $response = null;
        // The schema endpoint can precede this call (8 seconds). Bound all
        // compatibility attempts together to keep one browser request short.
        $deadline = microtime(true) + 12;
        foreach ($endpoints as $endpoint) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new FluentSyncException("Fluent Forms did not respond on page $page. Retry to resume this page.");
            }
            try {
                $response = Http::timeout(min(6, $remaining))->connectTimeout(min(3, $remaining))
                    ->withBasicAuth($website->ff_username, $website->ff_app_password)
                    ->acceptJson()->get($endpoint, ['form_id' => $formId, 'per_page' => $perPage, 'page' => $page]);
            } catch (ConnectionException) {
                throw new FluentSyncException("Fluent Forms did not respond on page $page. Retry to resume this page.");
            }
            if ($response->successful()) {
                break;
            }
            // Try a compatibility route only when this endpoint is unsupported.
            if (! in_array($response->status(), [404, 405], true)) {
                throw new FluentSyncException("Fluent Forms returned HTTP {$response->status()} on page $page. Retry to resume this page.");
            }
        }
        if (! $response?->successful()) {
            throw new FluentSyncException('No supported Fluent Forms submissions endpoint was found.');
        }
        try {
            $decoded = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FluentSyncException("Fluent Forms returned invalid JSON on page $page.");
        }
        [$submissions, $pagination] = $this->parseSubmissions($decoded);
        $hasMore = count($submissions) >= $perPage;
        $lastPage = $pagination['last_page'] ?? $pagination['total_pages'] ?? $response->header('X-WP-TotalPages');
        if (is_numeric($lastPage) && (int) $lastPage >= 1) {
            $hasMore = $page < (int) $lastPage;
        } elseif (array_key_exists('next_page_url', $pagination)) {
            $hasMore = ! empty($pagination['next_page_url']);
        }
        if ($submissions === [] && $hasMore) {
            throw new FluentSyncException('Fluent Forms returned an empty page before the end of its pagination.');
        }

        $normalized = [];
        foreach ($submissions as $submission) {
            $entry = json_decode(json_encode($submission, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($entry)) {
                throw new FluentSyncException('Fluent Forms returned an invalid submission record.');
            }
            $id = $entry['id'] ?? $entry['entry_id'] ?? $entry['entryId'] ?? $entry['submission_id'] ?? null;
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new FluentSyncException('Fluent Forms returned a submission without a valid entry ID.');
            }
            // Scoped routes may omit form_id. The global fallback must identify
            // every record, and explicit IDs must always match the requested form.
            $entryFormId = $entry['form_id'] ?? ($endpoint !== "$base/submissions" ? $formId : null);
            if (filter_var($entryFormId, FILTER_VALIDATE_INT) !== $formId) {
                throw new FluentSyncException('Fluent Forms returned submissions for a different or unidentified form.');
            }
            $normalized[(int) $id] = $entry;
        }
        $ids = array_keys($normalized);
        sort($ids, SORT_NUMERIC);

        // A failed page is retried as a whole. Preserve webhook data and generated
        // documents on existing entries; imports only fill missing records.
        $count = DB::transaction(function () use ($website, $formId, $normalized) {
            $count = 0;
            $existing = FfSubmission::where('website_id', $website->id)->where('form_id', $formId)
                ->whereIn('entry_id', array_keys($normalized))->pluck('entry_id')->flip();
            foreach ($normalized as $id => $entry) {
                if ($existing->has($id)) {
                    continue;
                }
                $submission = FfSubmission::firstOrCreate([
                    'website_id' => $website->id, 'form_id' => $formId, 'entry_id' => $id,
                ], [
                    'email' => $this->extractEmail($entry),
                    'created_at_wp' => $entry['created_at'] ?? $entry['date_created'] ?? now(),
                    'payload' => $entry,
                ]);
                $count += (int) $submission->wasRecentlyCreated;
            }

            return $count;
        });

        return ['count' => $count, 'updated' => 0, 'has_more' => $hasMore, 'entries_in_batch' => count($submissions), 'matched_count' => count($normalized), 'page_fingerprint' => hash('sha256', json_encode($ids))];
    }

    private function parseSubmissions(mixed $data): array
    {
        $pagination = [];
        for ($depth = 0; $depth < 6; $depth++) {
            if (is_array($data) && array_is_list($data)) {
                return [$data, $pagination];
            }
            $values = is_object($data) ? get_object_vars($data) : (is_array($data) ? $data : []);
            foreach (['last_page', 'total_pages', 'next_page_url'] as $key) {
                if (array_key_exists($key, $values)) {
                    $pagination[$key] = $values[$key];
                }
            }
            $next = false;
            foreach (['data', 'submissions', 'entries'] as $key) {
                if (array_key_exists($key, $values)) {
                    $data = $values[$key];
                    $next = true;
                    break;
                }
            }
            if (! $next) {
                break;
            }
        }

        throw new FluentSyncException('Fluent Forms returned an unrecognized submissions response.');
    }

    private function extractEmail(array $entry): ?string
    {
        $fields = ['email', 'Email', 'EMAIL', 'email_address', 'emailAddress', 'user_email', 'contact_email'];
        $response = $entry['response'] ?? [];
        if (is_string($response)) {
            $response = json_decode($response, true) ?? [];
        }
        foreach ([$entry, is_array($response) ? $response : []] as $values) {
            foreach ($fields as $key) {
                if (is_string($values[$key] ?? null) && filter_var($values[$key], FILTER_VALIDATE_EMAIL)) {
                    return $values[$key];
                }
            }
        }
        foreach (is_array($entry['inputs'] ?? null) ? $entry['inputs'] : [] as $input) {
            if (is_array($input) && is_string($input['name'] ?? null) && in_array(strtolower($input['name']), array_map('strtolower', $fields), true)
                && is_string($input['value'] ?? null) && filter_var($input['value'], FILTER_VALIDATE_EMAIL)) {
                return $input['value'];
            }
        }

        return null;
    }
}
