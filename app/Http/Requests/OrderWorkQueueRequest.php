<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderWorkQueueRequest extends FormRequest
{
    public const STAGES = ['all', 'prepare', 'ready', 'sending', 'sent', 'attention', 'waiting'];

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
            'website_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200'],
            'stage' => ['nullable', 'string', Rule::in(self::STAGES)], 'sort' => ['nullable', 'string', Rule::in(['oldest', 'newest'])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function filters(): array
    {
        $data = $this->validated();

        return ['website_id' => isset($data['website_id']) ? (int) $data['website_id'] : null, 'search' => $data['search'] ?? '',
            'stage' => $data['stage'] ?? 'all', 'sort' => $data['sort'] ?? 'oldest', 'per_page' => (int) ($data['per_page'] ?? 25)];
    }
}
