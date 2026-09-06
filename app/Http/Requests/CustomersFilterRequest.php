<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomersFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('website_ids'))) {
            $this->merge(['website_ids' => array_values(array_filter(array_map('trim', explode(',', $this->input('website_ids'))), fn ($id) => $id !== ''))]);
        }
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }

    public function rules(): array
    {
        return [
            'website_ids' => ['nullable', 'array', 'max:1000'],
            'website_ids.*' => ['integer', 'min:1'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', ...($this->filled('start_date') ? ['after_or_equal:start_date'] : [])],
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2,3}$/'],
            'payment_status' => ['nullable', Rule::in(['all', 'paid', 'pending'])],
            'min_spend' => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'sort_by' => ['nullable', Rule::in(['orders_count', 'total_spent', 'last_order_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            ...($this->routeIs('customers.export') ? [] : [
                'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]),
        ];
    }

    public function filters(): array
    {
        $values = $this->validated();
        $websiteIds = array_values(array_unique(array_map('intval', $values['website_ids'] ?? [])));
        sort($websiteIds, SORT_NUMERIC);

        return [
            'start_date' => $values['start_date'] ?? null,
            'end_date' => $values['end_date'] ?? null,
            'website_ids' => $websiteIds,
            'country' => $values['country'] ?? null,
            'min_spend' => isset($values['min_spend']) ? (float) $values['min_spend'] : null,
            'payment_status' => $values['payment_status'] ?? 'all',
            'sort_by' => $values['sort_by'] ?? 'last_order_at',
            'sort_dir' => $values['sort_dir'] ?? 'desc',
        ];
    }
}
