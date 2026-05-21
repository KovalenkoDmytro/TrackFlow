<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coming Soon — TrackFlow</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-900">TrackFlow</h1>
            <span class="text-sm text-gray-500">{{ $shop->name ?? 'Unknown shop' }}</span>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-8">

        <a href="{{ route('home') }}" class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 mb-6">
            &#8592; Back
        </a>

        <div class="bg-white rounded-lg border border-gray-200 p-8 text-center">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Coming Soon</h2>
            <p class="text-sm text-gray-500">This platform integration will be available in a future update.</p>
        </div>

    </main>
</body>
</html>
