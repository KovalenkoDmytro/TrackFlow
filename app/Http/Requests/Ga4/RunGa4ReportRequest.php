<?php

declare(strict_types=1);

namespace App\Http\Requests\Ga4;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the GA4 Data API `runReport` request the React SPA sends when
 * asking for basic reporting metrics for the authenticated shop's property.
 *
 * Kept intentionally small (a start/end date range plus fixed metric names)
 * — this is not a general-purpose passthrough to the Data API.
 */
final class RunGa4ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
