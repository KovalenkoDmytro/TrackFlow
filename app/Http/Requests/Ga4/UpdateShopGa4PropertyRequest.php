<?php

declare(strict_types=1);

namespace App\Http\Requests\Ga4;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the GA4 property_id a merchant assigns to their shop for the
 * read-only Data API reporting feature (see App\Models\ShopGa4Setting).
 *
 * property_id is the numeric GA4 property identifier (e.g. "123456789"),
 * not the "G-XXXXXXX" measurement_id used by the Measurement Protocol
 * integration in App\Http\Controllers\Api\Ga4ApiController.
 */
final class UpdateShopGa4PropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'string', 'regex:/^\d+$/'],
        ];
    }
}
