<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates query-string filter parameters for the analytics endpoint.
 *
 * Supports two modes:
 *   - single_day: show counts for a single calendar day identified by `date`
 *   - range:      show counts aggregated over a custom date range with max 366 days
 *
 * Authorization is always true — the API route middleware handles shop authentication.
 */
final class AnalyticsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        if (! $this->has('mode')) {
            $this->merge(['mode' => 'single_day']);
        }
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'string', 'in:single_day,range'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'required_if:mode,range'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'required_if:mode,range', 'after_or_equal:start_date'],
        ];
    }

    /**
     * Additional validation after the basic rules pass.
     *
     * Enforces the 366-day maximum range when mode is 'range'.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->input('mode') !== 'range') {
                return;
            }

            $start = $this->input('start_date');
            $end = $this->input('end_date');

            if ($start === null || $end === null) {
                return;
            }

            $days = (int) CarbonImmutable::parse($start)
                ->diffInDays(CarbonImmutable::parse($end));

            if ($days > 366) {
                $v->errors()->add('end_date', 'The date range may not exceed 366 days.');
            }
        });
    }
}
