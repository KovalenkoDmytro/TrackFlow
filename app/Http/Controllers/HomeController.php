<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $shop = $request->user();

        $integrations = $shop->platformIntegrations()
            ->where('active', true)
            ->get()
            ->keyBy(fn ($i) => $i->platform->value);

        return view('home', compact('shop', 'integrations'));
    }
}
