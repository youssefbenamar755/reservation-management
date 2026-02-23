<?php

namespace App\Services;

use App\Models\FfForm;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FluentFormSchemaService
{
    /**
     * Sync form schema from Fluent Forms API
     * 
     * @param Website $website
     * @param int $formId
     * @return FfForm|null
     */
    public function syncFormSchema(Website $website, int $formId): ?FfForm
    {
        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            Log::warning('Fluent Forms credentials not configured', [
                'website_id' => $website->id,
                'form_id' => $formId,
            ]);
            return null;
        }

        try {
            $baseUrl = rtrim($website->base_url, '/');
            $endpoint = "{$baseUrl}/wp-json/fluentform/v1/forms/{$formId}";

            $response = Http::timeout(8)
                ->connectTimeout(5)
                ->withBasicAuth($website->ff_username, $website->ff_app_password)
                ->acceptJson()
                ->get($endpoint);

            if (!$response->successful()) {
                Log::warning('Failed to fetch form schema', [
                    'website_id' => $website->id,
                    'form_id' => $formId,
                    'status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
                return null;
            }

            $formData = $response->json();
            
            if (!is_array($formData)) {
                Log::warning('Invalid form data structure', [
                    'website_id' => $website->id,
                    'form_id' => $formId,
                    'response_type' => gettype($formData),
                    'response_preview' => is_string($formData) ? substr($formData, 0, 200) : $formData,
                ]);
                return null;
            }
            
            // Log successful fetch for debugging
            Log::debug('Form schema fetched', [
                'website_id' => $website->id,
                'form_id' => $formId,
                'has_form_fields' => isset($formData['form_fields']),
                'has_fields' => isset($formData['fields']),
                'keys' => array_keys($formData),
            ]);

            // Extract form title
            $title = $formData['title'] ?? $formData['form_title'] ?? "Form #{$formId}";

            // Extract and normalize fields
            $fields = $this->extractFields($formData);

            // Store or update form schema
            $ffForm = FfForm::updateOrCreate(
                [
                    'website_id' => $website->id,
                    'form_id' => $formId,
                ],
                [
                    'title' => $title,
                    'fields' => $fields,
                ]
            );

            Log::info('Form schema synced successfully', [
                'website_id' => $website->id,
                'form_id' => $formId,
                'fields_count' => count($fields),
                'form_title' => $title,
                'sample_fields' => array_slice($fields, 0, 3, true), // First 3 fields for debugging
            ]);

            return $ffForm;
        } catch (\Throwable $e) {
            Log::error('Failed to sync form schema', [
                'website_id' => $website->id,
                'form_id' => $formId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract and normalize fields from form data
     * 
     * @param array $formData
     * @return array Normalized fields: { "names": { "label": "Name", "type": "name" }, ... }
     */
    private function extractFields(array $formData): array
    {
        $fields = [];

        // Fluent Forms stores form fields in different possible locations
        // Try form_fields first (most common), then fields, formFields, or formFieldsArray
        $formFields = $formData['form_fields'] 
            ?? $formData['fields'] 
            ?? $formData['formFields'] 
            ?? $formData['formFieldsArray']
            ?? [];

        // If formFields is a JSON string, decode it
        if (is_string($formFields)) {
            $decoded = json_decode($formFields, true);
            if (is_array($decoded)) {
                $formFields = $decoded;
            }
        }

        if (empty($formFields) || !is_array($formFields)) {
            Log::debug('No form fields found in response', [
                'available_keys' => array_keys($formData),
                'form_fields_type' => isset($formData['form_fields']) ? gettype($formData['form_fields']) : 'not_set',
            ]);
            return $fields;
        }
        
        Log::debug('Processing form fields', [
            'fields_count' => count($formFields),
            'first_field_keys' => !empty($formFields) && is_array($formFields[0] ?? null) ? array_keys($formFields[0]) : [],
        ]);

        // Process each field
        foreach ($formFields as $field) {
            if (!is_array($field)) {
                continue;
            }

            // Extract field key (name attribute) - check multiple possible locations
            $fieldKey = $field['name'] 
                ?? $field['attributes']['name'] 
                ?? null;
            
            // If still no key, try to extract from element + index
            if (empty($fieldKey)) {
                $element = $field['element'] ?? null;
                $index = $field['index'] ?? null;
                if ($element && $index !== null) {
                    $fieldKey = "{$element}_{$index}";
                } elseif ($element) {
                    $fieldKey = $element;
                }
            }
            
            if (empty($fieldKey)) {
                continue;
            }

            // Extract label - check multiple possible locations
            $label = $field['label'] 
                ?? $field['settings']['label'] 
                ?? $field['attributes']['label']
                ?? $field['settings']['admin_label']
                ?? null;
            
            // If no label, try to format the key
            if (empty($label)) {
                $label = $this->formatFieldLabel($fieldKey);
            }

            // Extract field type
            $type = $field['element'] 
                ?? $field['type'] 
                ?? $field['attributes']['type'] 
                ?? 'text';

            // Store normalized field (use base key to group repeated fields)
            $baseKey = self::getBaseFieldKey($fieldKey);
            $fields[$baseKey] = [
                'label' => $label,
                'type' => $type,
            ];
            
            // Also store with the exact key if different from base key
            if ($fieldKey !== $baseKey) {
                $fields[$fieldKey] = [
                    'label' => $label,
                    'type' => $type,
                ];
            }
        }

        return $fields;
    }

    /**
     * Format field key to human-readable label (fallback)
     * 
     * @param string $key
     * @return string
     */
    private function formatFieldLabel(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * Get base field key (remove numeric suffix for repeated fields)
     * 
     * Examples:
     * - names_5 -> names
     * - names_12 -> names
     * - title_3 -> title
     * - names -> names
     * 
     * @param string $key
     * @return string
     */
    public static function getBaseFieldKey(string $key): string
    {
        // Match pattern: base_name_123 (where 123 is optional numeric suffix)
        if (preg_match('/^(.+?)_(\d+)$/', $key, $matches)) {
            return $matches[1];
        }
        
        return $key;
    }

    /**
     * Get field label from schema
     * 
     * @param FfForm|null $formSchema
     * @param string $fieldKey
     * @return string
     */
    public static function getFieldLabel(?FfForm $formSchema, string $fieldKey): string
    {
        if (!$formSchema || !$formSchema->fields) {
            // Fallback: format the key
            return ucwords(str_replace(['_', '-'], ' ', $fieldKey));
        }

        $fields = $formSchema->fields;
        $baseKey = self::getBaseFieldKey($fieldKey);

        // Try exact match first
        if (isset($fields[$fieldKey]) && isset($fields[$fieldKey]['label'])) {
            return $fields[$fieldKey]['label'];
        }

        // Try base key match
        if (isset($fields[$baseKey]) && isset($fields[$baseKey]['label'])) {
            return $fields[$baseKey]['label'];
        }

        // Fallback: format the key
        return ucwords(str_replace(['_', '-'], ' ', $fieldKey));
    }
}

