<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OauthCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only status endpoint for the operator "Connect Google Account" panel
 * in the React SPA (see App\Actions\Google and the "connect-google" Gate).
 *
 * Exposes only whether the shared credential is connected and whether the
 * current shop is an operator — never the refresh_token itself. Any
 * authenticated shop may call this (so the client can decide whether to
 * render the panel at all); the actual connect/disconnect routes still
 * enforce the Gate server-side regardless of what this reports.
 */
final class GoogleOperatorStatusApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $credential = OauthCredential::query()->where('provider', 'google')->first();

        return response()->json([
            'is_operator' => Gate::forUser($request->user())->allows('connect-google'),
            'connected' => $credential !== null
                && $credential->revoked_at === null
                && $credential->refresh_token !== null,
            'connected_at' => $credential?->connected_at,
        ]);
    }
}
