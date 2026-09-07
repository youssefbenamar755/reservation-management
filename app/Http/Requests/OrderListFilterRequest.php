<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderListFilterRequest extends FormRequest
{
    public const STATUSES = ['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
    }

    public function rules(): array
    {
        return [
            'website_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'search' => ['nullable', 'string', 'max:200'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', ...($this->filled('start_date') ? ['after_or_equal:start_date'] : [])],
            'sort' => ['nullable', 'string', Rule::in(['newest', 'oldest', 'highest', 'lowest'])],
            'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function filters(): array
    {
        $filters = $this->safe()->except('page');
        foreach (['website_id', 'per_page'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }
}
