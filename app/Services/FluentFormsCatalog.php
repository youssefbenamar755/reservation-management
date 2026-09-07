<?php

namespace App\Services;

use App\Exceptions\FluentSyncException;
use App\Models\Website;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class FluentFormsCatalog
{
    /** Return only real forms after every catalog page has been validated. */
    public function fetch(Website $website): array
    {
        if (! $website->ff_username || ! $website->ff_app_password) {
            throw new FluentSyncException('Fluent Forms credentials are not configured.');
        }

        $endpoint = rtrim($website->base_url, '/').'/wp-json/fluentform/v1/forms';
        $deadline = microtime(true) + 12;
        $forms = [];
        $seenPages = [];

        for ($page = 1; $page <= 20; $page++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new FluentSyncException('Fetching the complete forms list timed out. Try again.');
            }
            try {
                $response = Http::timeout(min(6, $remaining))->connectTimeout(min(3, $remaining))
                    ->withBasicAuth($website->ff_username, $website->ff_app_password)
                    ->acceptJson()->get($endpoint, ['page' => $page, 'per_page' => 100]);
            } catch (ConnectionException) {
                throw new FluentSyncException('Fluent Forms did not respond while fetching forms. Try again.');
            }
            if (! $response->successful()) {
                throw new FluentSyncException("Fluent Forms returned HTTP {$response->status()} while fetching forms.");
            }
            try {
                $decoded = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new FluentSyncException('Fluent Forms returned an invalid forms list.');
            }
            [$records, $pagination] = $this->parsePage($decoded);
            if (isset($pagination['current_page']) && $this->positiveInt($pagination['current_page']) !== $page) {
                throw new FluentSyncException('Fluent Forms did not advance its forms page.');
            }
            $pageForms = [];
            foreach ($records as $record) {
                $id = $this->positiveInt(is_object($record) ? ($record->id ?? null) : $record);
                if ($id === null) {
                    throw new FluentSyncException('Fluent Forms returned a form without a valid ID.');
                }
                $title = is_object($record) ? ($record->title ?? $record->form_title ?? null) : null;
                $pageForms[$id] = ['id' => $id, 'title' => is_string($title) && trim($title) !== '' ? $title : "Form #$id"];
            }

            $ids = array_keys($pageForms);
            sort($ids, SORT_NUMERIC);
            $fingerprint = hash('sha256', json_encode($ids));
            if ($ids !== [] && isset($seenPages[$fingerprint])) {
                throw new FluentSyncException('Fluent Forms repeated a forms page. The list is incomplete.');
            }
            $seenPages[$fingerprint] = true;
            $forms += $pageForms;

            $lastPage = $pagination['last_page'] ?? $pagination['total_pages'] ?? $response->header('X-WP-TotalPages');
            if ($lastPage !== null && $lastPage !== '') {
                $lastPage = $this->positiveInt($lastPage);
                if ($lastPage === null) {
                    throw new FluentSyncException('Fluent Forms returned invalid forms pagination.');
                }
                $hasMore = $page < $lastPage;
            } elseif (array_key_exists('next_page_url', $pagination)) {
                // Never send credentials to a returned URL; advance the same endpoint.
                $hasMore = ! empty($pagination['next_page_url']);
            } elseif (isset($pagination['total'], $pagination['per_page'])) {
                $total = filter_var($pagination['total'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $perPage = $this->positiveInt($pagination['per_page']);
                if ($total === false || $perPage === null) {
                    throw new FluentSyncException('Fluent Forms returned invalid forms pagination.');
                }
                $hasMore = $page * $perPage < $total;
            } else {
                $hasMore = false;
            }
            if (! $hasMore) {
                return array_values($forms);
            }
            if ($records === []) {
                throw new FluentSyncException('Fluent Forms returned an empty forms page before pagination completed.');
            }
        }

        throw new FluentSyncException('Fluent Forms exceeded the forms page limit. The list is incomplete.');
    }

    private function parsePage(mixed $data): array
    {
        $pagination = [];
        for ($depth = 0; $depth < 6; $depth++) {
            if (is_array($data) && array_is_list($data)) {
                return [$data, $pagination];
            }
            if (! is_object($data)) {
                break;
            }
            foreach (['current_page', 'last_page', 'total_pages', 'next_page_url', 'total', 'per_page'] as $key) {
                if (property_exists($data, $key)) {
                    $pagination[$key] = $data->$key;
                }
            }
            if (property_exists($data, 'forms')) {
                $data = $data->forms;
            } elseif (property_exists($data, 'data')) {
                $data = $data->data;
            } else {
                break;
            }
        }

        throw new FluentSyncException('Fluent Forms returned an unrecognized forms list.');
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value))) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
