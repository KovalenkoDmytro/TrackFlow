<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'TrackFlow')</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window['app-bridge'] && {{ request()->query('embedded', 0) }}) {
                window['app-bridge'].createApp({
                    apiKey: '{{ config('shopify-app.api_key') }}',
                    host: '{{ request()->query('host', '') }}',
                });
            }
        });
    </script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-900">TrackFlow</h1>
            <span class="text-sm text-gray-500">{{ $shop->name ?? 'Unknown shop' }}</span>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-8">
        @if (session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm mb-6">
                {{ session('success') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
