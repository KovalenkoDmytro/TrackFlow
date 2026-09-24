#!/bin/sh
set -e

# Generate APP_KEY only if .env doesn't have a valid one (prevent destructive regeneration)
if ! grep -q '^APP_KEY=base64:.\+' .env 2>/dev/null; then
    echo "Generating APP_KEY..."
    php artisan key:generate --no-interaction
fi

# Clear bootstrap cache to ensure service providers are discovered fresh
echo "Clearing framework cache..."
php artisan optimize:clear --quiet || true

# Run migrations on every startup (ensures schema is fresh for dev environment)
echo "Running migrations..."
php artisan migrate --force

# Start Octane server with FrankenPHP
echo "Starting Laravel Octane with FrankenPHP..."
exec php artisan octane:start
