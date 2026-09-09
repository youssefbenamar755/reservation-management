<?php

namespace App\Http\Requests;

use App\Services\UsefulAlerts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UsefulAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['website_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(['active', 'snoozed', 'resolved', 'all'])],
            'kind' => ['nullable', 'string', Rule::in(['all', ...UsefulAlerts::KINDS])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000']];
    }

    public function filters(): array
    {
        $data = $this->validated();

        return ['website_id' => isset($data['website_id']) ? (int) $data['website_id'] : null,
            'status' => $data['status'] ?? 'active', 'kind' => $data['kind'] ?? 'all'];
    }
}
