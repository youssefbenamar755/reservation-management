<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SeoFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public static function maxEndDate(): string
    {
        return CarbonImmutable::today('America/Los_Angeles')->subDays(3)->toDateString();
    }

    public function rules(): array
    {
        $detail = $this->routeIs('seo.page', 'seo.page.refresh');

        return [
            'website_id' => [$detail ? 'required' : 'nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.self::maxEndDate()],
            'page_url' => $detail ? ['bail', 'required', 'string', 'max:2048', 'url:http,https', function ($attribute, $value, $fail) {
                $parts = parse_url($value);
                if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $value)) {
                    $fail('Choose a valid page URL without login information.');
                }
            }] : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['end_date.before_or_equal' => 'Choose an end date on or before '.self::maxEndDate().'. SEO comparisons exclude the latest three days in Pacific Time to allow Search Console processing.'];
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
        $end = $this->input('end_date') ?: self::maxEndDate();

        return [
            'website_id' => $this->filled('website_id') ? (int) $this->input('website_id') : null,
            'start_date' => $this->input('start_date') ?: CarbonImmutable::parse($end, 'UTC')->subDays(27)->toDateString(),
            'end_date' => $end,
        ];
    }
}
