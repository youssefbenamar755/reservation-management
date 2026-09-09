<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TrafficFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'website_id' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today('UTC')->toDateString()],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $range = $this->filters();
            $days = CarbonImmutable::parse($range['start_date'], 'UTC')->diffInDays(CarbonImmutable::parse($range['end_date'], 'UTC'), false);
            if ($days < 0) {
                $validator->errors()->add('end_date', 'End date must be on or after the start date.');
            } elseif ($days >= 93) {
                $validator->errors()->add('end_date', 'Choose a date range of 93 days or fewer.');
            }
        }];
    }

    public function filters(): array
    {
        $end = $this->input('end_date') ?: CarbonImmutable::yesterday('UTC')->toDateString();

        return [
            'website_id' => $this->filled('website_id') ? (int) $this->input('website_id') : null,
            'start_date' => $this->input('start_date') ?: CarbonImmutable::parse($end, 'UTC')->subDays(27)->toDateString(),
            'end_date' => $end,
        ];
    }
}
