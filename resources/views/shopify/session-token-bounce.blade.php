<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="shopify-api-key" content="{{ config('shopify-app.api_key') }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
</head>
<body>
    <script>
        (function () {
            if (typeof shopify === 'undefined') {
                document.body.textContent = 'This app must be opened from your Shopify admin.';

                return;
            }

            var reloadTarget = new URLSearchParams(window.location.search).get('shopify-reload');

            if (!reloadTarget) {
                document.body.textContent = 'Missing reload target.';

                return;
            }

            var target;
            try {
                // Resolving against window.location.origin lets the URL API fully
                // normalise the value first (including quirks like a leading
                // "/\host", which some browsers treat as protocol-relative
                // "//host"), so the same-origin check below sees the URL the
                // browser will actually navigate to — never trust a raw string
                // comparison (e.g. "starts with /") done before normalisation.
                target = new URL(reloadTarget, window.location.origin);
            } catch (error) {
                document.body.textContent = 'Invalid reload target.';

                return;
            }

            if (target.origin !== window.location.origin) {
                document.body.textContent = 'Refusing to reload to a different origin.';

                return;
            }

            shopify.idToken().then(function (idToken) {
                target.searchParams.set('id_token', idToken);
                window.location.replace(target.toString());
            }).catch(function (error) {
                document.body.textContent = 'We could not connect to Shopify. Please reload this page to try again.';

                if (window.console && console.error) {
                    console.error('Shopify App Bridge idToken() failed', error);
                }
            });
        })();
    </script>
</body>
</html>
