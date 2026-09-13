<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActionHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => ltrim(trim($this->input('search')), '#')]);
        }
    }

    public function rules(): array
    {
        return ['website_id' => ['nullable', 'integer', 'min:1'], 'actor_id' => ['nullable', 'integer', 'min:1'],
            'order_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'regex:/\A[0-9]{1,20}\z/D'],
            'category' => ['nullable', Rule::in(['all', 'orders', 'email', 'webhooks', 'alerts'])],
            'outcome' => ['nullable', Rule::in(['all', 'succeeded', 'failed', 'uncertain', 'pending', 'skipped'])],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', ...($this->filled('start_date') ? ['after_or_equal:start_date'] : [])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000']];
    }

    public function filters(): array
    {
        $data = $this->validated();

        return ['website_id' => isset($data['website_id']) ? (int) $data['website_id'] : null,
            'actor_id' => isset($data['actor_id']) ? (int) $data['actor_id'] : null,
            'order_id' => isset($data['order_id']) ? (int) $data['order_id'] : null,
            'search' => $data['search'] ?? '', 'category' => $data['category'] ?? 'all', 'outcome' => $data['outcome'] ?? 'all',
            'start_date' => $data['start_date'] ?? '', 'end_date' => $data['end_date'] ?? ''];
    }
}
