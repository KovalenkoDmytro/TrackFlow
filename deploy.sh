#!/bin/bash
set -e

##############################################################################
# TrackFlow Staging Deployment Script
#
# This script automates the deployment process for the staging environment.
# It pulls the latest code, installs dependencies, builds assets, runs
# migrations, clears caches, and finally purges the Cloudflare cache to
# ensure fresh content is served.
#
# Usage:
#   ./deploy.sh
#
# Prerequisites:
#   - Run this script on the staging server via SSH
#   - Environment variables must be set (see README_OCTANE_DEPLOYMENT.md):
#     * CLOUDFLARE_ZONE_ID
#     * CLOUDFLARE_API_TOKEN
#   - PHP 8.4+ (or whatever version is in use on the server)
#   - npm or yarn available on $PATH
#   - Supervisor configured for trackflow-octane and trackflow-queue
#
##############################################################################

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🚀 TrackFlow Staging Deployment"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Get script directory (allows running from anywhere)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

PHP_BIN="/usr/bin/php8.4"

# Step 1: Pull latest code
echo "📥 Pulling latest code from dev-dmytro..."
git pull origin dev-dmytro
echo ""

# Step 2: Install backend dependencies
echo "📦 Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader
echo ""

# Step 3: Install and build frontend assets
echo "🏗️ Building frontend assets (Vite)..."
npm install && npm run build
echo ""

# Step 4: Run database migrations
echo "🗄️ Running database migrations..."
$PHP_BIN artisan migrate --force
echo ""

# Step 5: Clear framework caches
echo "🧹 Clearing framework caches..."
$PHP_BIN artisan optimize:clear
echo ""

# Step 6: Restart application and queue workers
#
# Octane (FrankenPHP) workers are long-lived and cache the Vite manifest
# (Illuminate\Foundation\Vite::$manifests) in memory. If they are not
# restarted after `npm run build`, they keep serving HTML that references
# the old (now-deleted) hashed asset filenames, causing 404s on JS/CSS and a
# white screen — regardless of any HTTP/CDN caching. `octane:reload` is a
# graceful, in-process worker restart (no dropped connections) and is tried
# first; supervisorctl restart is only a fallback for environments where
# octane:reload isn't available/configured (e.g. Octane not installed or
# process manager not running under Supervisor).
echo "♻️ Restarting Octane workers..."

if $PHP_BIN artisan octane:reload; then
    echo "✅ Octane workers reloaded gracefully."
elif command -v supervisorctl >/dev/null 2>&1; then
    echo "⚠️  octane:reload unavailable, falling back to supervisorctl restart."
    sudo supervisorctl restart trackflow-octane:*
else
    echo "❌ Neither octane:reload nor supervisorctl are available."
    echo "   Octane workers were NOT restarted — stale Vite manifest may still be served."
fi
echo ""

echo "♻️ Restarting queue workers..."
if command -v supervisorctl >/dev/null 2>&1; then
    sudo supervisorctl restart trackflow-queue:*
else
    echo "⚠️  supervisorctl not available — queue workers were NOT restarted."
fi
echo ""

# Step 7: Purge Cloudflare cache
echo "☁️ Purging Cloudflare cache..."

# Reads a single key from the Laravel .env file next to this script, without
# sourcing/executing it (the .env may contain values with spaces, quotes, or
# other shell-unsafe characters). Only the exact key requested is extracted;
# the rest of the file is never parsed or evaluated. A single layer of
# surrounding quotes, as commonly used in .env files, is stripped.
read_env_value() {
    local key="$1"
    local env_file="${SCRIPT_DIR}/.env"
    local value=""

    if [[ -f "$env_file" ]]; then
        value=$(grep -m1 -E "^${key}=" "$env_file" | cut -d '=' -f2- || true)
        value="${value%\"}"
        value="${value#\"}"
        value="${value%\'}"
        value="${value#\'}"
    fi

    printf '%s' "$value"
}

# Shell/process env vars take priority (e.g. CI runners); otherwise fall back
# to the app's .env file, per the setup instructions in
# README_OCTANE_DEPLOYMENT.md.
CLOUDFLARE_ZONE_ID="${CLOUDFLARE_ZONE_ID:-$(read_env_value CLOUDFLARE_ZONE_ID)}"
CLOUDFLARE_API_TOKEN="${CLOUDFLARE_API_TOKEN:-$(read_env_value CLOUDFLARE_API_TOKEN)}"

if [[ -z "$CLOUDFLARE_ZONE_ID" || -z "$CLOUDFLARE_API_TOKEN" ]]; then
    echo "⚠️  CLOUDFLARE_ZONE_ID and/or CLOUDFLARE_API_TOKEN not set."
    echo "   Skipping Cloudflare cache purge (you may need to purge manually)."
    echo "   See README_OCTANE_DEPLOYMENT.md for setup instructions."
    echo ""
else
    # Purge the entire cache for the zone
    RESPONSE=$(curl -s -X POST "https://api.cloudflare.com/client/v4/zones/${CLOUDFLARE_ZONE_ID}/purge_cache" \
        -H "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data '{"purge_everything":true}')

    # Check if the response indicates success
    if echo "$RESPONSE" | grep -q '"success":true'; then
        echo "✅ Cloudflare cache purged successfully."
    else
        echo "❌ Failed to purge Cloudflare cache."
        echo "   Response: $RESPONSE"
        echo "   You may need to purge manually via the Cloudflare dashboard."
    fi
    echo ""
fi

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Deployment complete!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
