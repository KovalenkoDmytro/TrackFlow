@extends('layouts.app')

@section('title', 'TrackFlow')

@section('content')
    {{-- Pixel status --}}
    <section>
        <h2 class="text-lg font-semibold text-gray-800 mb-3">Pixel Status</h2>
        <div class="bg-white rounded-lg border border-gray-200 p-4 flex items-center gap-3">
            @if ($shop->shopify_pixel_id)
                <span class="inline-flex items-center gap-1.5 text-sm font-medium text-green-700">
                    <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                    Active
                </span>
                <span class="text-xs text-gray-400 font-mono">{{ $shop->shopify_pixel_id }}</span>
            @else
                <span class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500">
                    <span class="w-2.5 h-2.5 rounded-full bg-gray-300"></span>
                    Inactive — pixel will be created on next authentication
                </span>
            @endif
        </div>
    </section>

    {{-- Platform integrations --}}
    <section class="mt-8">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">Platform Integrations</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <div class="bg-white rounded-lg border border-gray-200 p-5 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-sm">G</div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">Google Ads</p>
                        @if (isset($integrations['google_ads']))
                            <p class="text-xs text-green-600 font-medium">Connected</p>
                        @else
                            <p class="text-xs text-gray-400">Not Connected</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('settings.google-ads') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 border border-indigo-200 rounded px-3 py-1.5 hover:bg-indigo-50 transition">
                    {{ isset($integrations['google_ads']) ? 'Manage' : 'Connect' }}
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-5 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-sm">M</div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">Meta</p>
                        @if (isset($integrations['meta']))
                            <p class="text-xs text-green-600 font-medium">Connected</p>
                        @else
                            <p class="text-xs text-gray-400">Not Connected</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('settings.meta') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 border border-indigo-200 rounded px-3 py-1.5 hover:bg-indigo-50 transition">
                    {{ isset($integrations['meta']) ? 'Manage' : 'Connect' }}
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-5 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-gray-900 flex items-center justify-center text-white font-bold text-sm">T</div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">TikTok</p>
                        @if (isset($integrations['tiktok']))
                            <p class="text-xs text-green-600 font-medium">Connected</p>
                        @else
                            <p class="text-xs text-gray-400">Not Connected</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('settings.tiktok') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 border border-indigo-200 rounded px-3 py-1.5 hover:bg-indigo-50 transition">
                    {{ isset($integrations['tiktok']) ? 'Manage' : 'Connect' }}
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-5 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-orange-100 flex items-center justify-center text-orange-600 font-bold text-sm">A</div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">GA4</p>
                        @if (isset($integrations['ga4']))
                            <p class="text-xs text-green-600 font-medium">Connected</p>
                        @else
                            <p class="text-xs text-gray-400">Not Connected</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('settings.ga4') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 border border-indigo-200 rounded px-3 py-1.5 hover:bg-indigo-50 transition">
                    {{ isset($integrations['ga4']) ? 'Manage' : 'Connect' }}
                </a>
            </div>

        </div>
    </section>
@endsection
