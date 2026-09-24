<!DOCTYPE html>
<html lang="en">
<head>
    @unless (config('shopify-app.dev_auth_bypass'))
        <meta name="shopify-api-key" content="{{ config('shopify-app.api_key') }}">
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    @endunless
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TrackFlow</title>
    @viteReactRefresh
    @vite(['resources/js/main.tsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
