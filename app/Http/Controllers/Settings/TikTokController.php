<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * TikTok Events API integration controller.
 *
 * Currently shows a "coming soon" placeholder. Will be implemented in Phase 2.
 * The controller exists now so routes, naming conventions, and namespace
 * are consistent with the other platform controllers.
 */
final class TikTokController extends Controller
{
    /**
     * Display the TikTok settings page (coming soon placeholder).
     */
    public function show(Request $request): View
    {
        $shop = $request->user();

        return view('settings.coming-soon', compact('shop'));
    }
}
