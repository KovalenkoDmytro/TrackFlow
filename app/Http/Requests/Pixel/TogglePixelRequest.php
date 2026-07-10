<?php

declare(strict_types=1);

namespace App\Http\Requests\Pixel;

use Illuminate\Foundation\Http\FormRequest;

final class TogglePixelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated shop may toggle its own pixel — scoping to $request->user()
        // is enforced in the controller, there is no cross-shop resource to authorize.
        return true;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
