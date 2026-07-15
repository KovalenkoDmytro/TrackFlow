<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>Connecting to Shopify…</title>
</head>
<body>
    @if($failed)
        <p>We couldn't finish connecting your store to Shopify.</p>
        <button id="retry-button" type="button">Try again</button>
        <script>
            document.getElementById('retry-button').addEventListener('click', function () {
                // Strip the `id_token`/`code` that led to the failure so this
                // reload lands back on the "fetch a fresh token" branch below,
                // rather than immediately resubmitting the same failed token.
                var target = new URL(window.location.href);
                target.searchParams.delete('id_token');
                target.searchParams.delete('code');

                open(target.toString(), '_self');
            });
        </script>
    @else
        <script>
            (function () {
                // If App Bridge never initialised, we are not being loaded inside the
                // Shopify Admin iframe (e.g. someone hit this URL directly). There is
                // nothing we can do here — bail out rather than looping.
                if (typeof shopify === 'undefined') {
                    document.body.textContent = 'This app must be opened from your Shopify admin.';

                    return;
                }

                shopify.idToken().then(function (idToken) {
                    // Submit as POST rather than carrying the id_token in the URL:
                    // GET query strings end up in server access logs and browser
                    // history, and this token is sensitive.
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.pathname + window.location.search;

                    var csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = '_token';
                    csrfInput.value = document.querySelector('meta[name="csrf-token"]').content;
                    form.appendChild(csrfInput);

                    var tokenInput = document.createElement('input');
                    tokenInput.type = 'hidden';
                    tokenInput.name = 'id_token';
                    tokenInput.value = idToken;
                    form.appendChild(tokenInput);

                    document.body.appendChild(form);
                    form.submit();
                }).catch(function (error) {
                    // App Bridge init failure, network error, revoked session, etc.
                    // Without this the page would hang silently forever — show the
                    // merchant a way out instead.
                    document.body.textContent = 'We could not connect to Shopify. Please reload this page to try again.';

                    if (window.console && console.error) {
                        console.error('Shopify App Bridge idToken() failed', error);
                    }
                });
            })();
        </script>
    @endif
</body>
</html>
