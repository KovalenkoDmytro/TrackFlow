<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Ads — TrackFlow</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-900">TrackFlow</h1>
            <span class="text-sm text-gray-500">{{ $shop->name ?? 'Unknown shop' }}</span>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-8">

        <a href="{{ route('home') }}" class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 mb-6">
            &#8592; Back
        </a>

        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-800">Google Ads Integration</h2>

            @if ($integration && $integration->active)
                <form method="POST" action="{{ route('settings.google-ads.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        onclick="return confirm('Disconnect Google Ads? This will stop all conversion tracking.')"
                        class="inline-flex items-center rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-600 shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2 transition"
                    >
                        Disconnect
                    </button>
                </form>
            @endif
        </div>

        @error('credentials')
            <div class="mb-5 rounded-md bg-red-50 border border-red-200 px-4 py-3">
                <p class="text-sm text-red-700">{{ $message }}</p>
            </div>
        @enderror

        <form method="POST" action="{{ route('settings.google-ads.store') }}" class="space-y-5">
            @csrf

            <div>
                <label for="customer_id" class="block text-sm font-medium text-gray-700 mb-1">Customer ID</label>
                <input
                    type="text"
                    id="customer_id"
                    name="customer_id"
                    value="{{ old('customer_id', $credentials['customer_id'] ?? '') }}"
                    placeholder="123-456-7890"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 @error('customer_id') border-red-400 @enderror"
                >
                <p class="mt-1 text-xs text-gray-500">Your Google Ads account ID (not MCC)</p>
                @error('customer_id')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="mcc_id" class="block text-sm font-medium text-gray-700 mb-1">MCC Customer ID <span class="text-gray-400 font-normal">(optional)</span></label>
                <input
                    type="text"
                    id="mcc_id"
                    name="mcc_id"
                    value="{{ old('mcc_id', $credentials['mcc_id'] ?? '') }}"
                    placeholder="123-456-7890"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 @error('mcc_id') border-red-400 @enderror"
                >
                <p class="mt-1 text-xs text-gray-500">Leave blank if you don't use a manager account</p>
                @error('mcc_id')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="developer_token" class="block text-sm font-medium text-gray-700 mb-1">Developer Token</label>
                <input
                    type="text"
                    id="developer_token"
                    name="developer_token"
                    value="{{ old('developer_token', $credentials['developer_token'] ?? '') }}"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 @error('developer_token') border-red-400 @enderror"
                >
                @error('developer_token')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="oauth_client_id" class="block text-sm font-medium text-gray-700 mb-1">OAuth Client ID</label>
                <input
                    type="text"
                    id="oauth_client_id"
                    name="oauth_client_id"
                    value="{{ old('oauth_client_id', $credentials['oauth']['client_id'] ?? '') }}"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 @error('oauth_client_id') border-red-400 @enderror"
                >
                @error('oauth_client_id')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="oauth_client_secret" class="block text-sm font-medium text-gray-700 mb-1">OAuth Client Secret</label>
                <input
                    type="password"
                    id="oauth_client_secret"
                    name="oauth_client_secret"
                    value="{{ old('oauth_client_secret', $credentials['oauth']['client_secret'] ?? '') }}"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 @error('oauth_client_secret') border-red-400 @enderror"
                >
                @error('oauth_client_secret')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="oauth_refresh_token" class="block text-sm font-medium text-gray-700 mb-1">OAuth Refresh Token</label>
                <textarea
                    id="oauth_refresh_token"
                    name="oauth_refresh_token"
                    rows="3"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 @error('oauth_refresh_token') border-red-400 @enderror"
                >{{ old('oauth_refresh_token', $credentials['oauth']['refresh_token'] ?? '') }}</textarea>
                @error('oauth_refresh_token')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-2">
                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition"
                >
                    Save &amp; Connect
                </button>
            </div>

        </form>

        @if ($integration && $integration->conversionActionMappings->isNotEmpty())
            <div class="mt-10">
                <h3 class="text-base font-semibold text-gray-800 mb-3">Conversion Actions</h3>
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Event</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Google Ads Action ID</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($integration->conversionActionMappings as $mapping)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-gray-700">{{ $mapping->event }}</td>
                                    <td class="px-4 py-3">
                                        @if ($mapping->active)
                                            <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">Active</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/20">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $mapping->external_action_id ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

    </main>
</body>
</html>
