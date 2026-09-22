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
 *   - range:      show counts aggregated over the configured retention period
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
        $timezone = config('app.timezone', 'UTC');
        $retentionDays = (int) config('tracking.retention_days', 90);
        $today = CarbonImmutable::now($timezone)->toDateString();
        $earliestDate = CarbonImmutable::now($timezone)->subDays($retentionDays)->toDateString();

        return [
            'mode' => ['nullable', 'string', 'in:single_day,range'],
            'date' => ['nullable', 'date_format:Y-m-d', "after_or_equal:{$earliestDate}", "before_or_equal:{$today}"],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'required_if:mode,range', "after_or_equal:{$earliestDate}", "before_or_equal:{$today}"],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'required_if:mode,range', 'after_or_equal:start_date', "after_or_equal:{$earliestDate}", "before_or_equal:{$today}"],
            'platform' => ['nullable', 'string', 'in:google_ads,meta,tiktok,ga4'],
        ];
    }

    /**
     * Additional validation after the basic rules pass.
     *
     * Enforces the configured retention window when mode is 'range'.
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

            $retentionDays = (int) config('tracking.retention_days', 90);
            if ($days > $retentionDays) {
                $v->errors()->add('end_date', "The date range may not exceed {$retentionDays} days.");
            }
        });
    }
}
