<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HealthFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'website_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(['all', 'queued', 'processed', 'failed'])],
            'source' => ['nullable', 'string', Rule::in(['all', 'woocommerce', 'fluentforms'])],
            'range' => ['nullable', 'string', Rule::in(['24h', '7d', '30d', 'all'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function filters(): array
    {
        $values = $this->validated();

        return [
            'website_id' => isset($values['website_id']) ? (int) $values['website_id'] : null,
            'status' => $values['status'] ?? 'failed',
            'source' => $values['source'] ?? 'all',
            'range' => $values['range'] ?? '24h',
        ];
    }
}
