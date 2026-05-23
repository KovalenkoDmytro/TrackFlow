<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TrackFlow</title>
    @viteReactRefresh
    @vite(['resources/js/main.jsx'])
</head>
<body>
    <div id="root"></div>
    <script>
        window.__SHOPIFY_API_KEY__ = "{{ config('shopify-app.api_key') }}";
    </script>
</body>
</html>
