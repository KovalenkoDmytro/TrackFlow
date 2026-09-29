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
# Aborts before pulling if the .env is unsafe for a non-local APP_ENV
# (SHOPIFY_DEV_AUTH_BYPASS=true, APP_DEBUG=true, SHOPIFY_DEV_SHOP_DOMAIN set),
# and aborts before migrations/restarts if the app cannot boot.
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

# Reads a single key from the Laravel .env file next to this script, without
# sourcing/executing it (the .env may contain values with spaces, quotes, or
# other shell-unsafe characters). The last occurrence wins, as in Laravel's
# dotenv loader. ENV_FILE overrides the path (used for testing). Only the exact key requested is extracted;
# the rest of the file is never parsed or evaluated. A single layer of
# surrounding quotes, as commonly used in .env files, is stripped.
read_env_value() {
    local key="$1"
    local env_file="${ENV_FILE:-${SCRIPT_DIR}/.env}"
    local value=""

    if [[ -f "$env_file" ]]; then
        value=$(grep -E "^${key}=" "$env_file" | tail -n1 | cut -d '=' -f2- || true)
        value="${value%\"}"
        value="${value#\"}"
        value="${value%\'}"
        value="${value#\'}"
    fi

    printf '%s' "$value"
}

is_truthy() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        true|1|yes|on|"(true)") return 0 ;;
        *) return 1 ;;
    esac
}

# Pre-flight: refuse to deploy over a misconfigured .env. Prints variable NAMES
# only, never values. Runs before anything is pulled, installed or restarted.
preflight_env_check() {
    local env_file="${ENV_FILE:-${SCRIPT_DIR}/.env}"
    local bad=()

    if [[ ! -f "$env_file" ]]; then
        echo "❌ Pre-flight failed: .env not found." >&2
        return 1
    fi

    if [[ "$(read_env_value APP_ENV)" != "local" ]]; then
        is_truthy "$(read_env_value SHOPIFY_DEV_AUTH_BYPASS)" && bad+=("SHOPIFY_DEV_AUTH_BYPASS=true")
        is_truthy "$(read_env_value APP_DEBUG)" && bad+=("APP_DEBUG=true")
        [[ -n "$(read_env_value SHOPIFY_DEV_SHOP_DOMAIN)" ]] && bad+=("SHOPIFY_DEV_SHOP_DOMAIN (must be empty)")
    fi

    if (( ${#bad[@]} > 0 )); then
        echo "❌ Pre-flight failed: unsafe .env settings for a non-local APP_ENV:" >&2
        printf '   - %s\n' "${bad[@]}" >&2
        echo "   Fix the server .env and re-run. Nothing was pulled or restarted." >&2
        return 1
    fi

    echo "✅ Pre-flight .env checks passed."
}

# DEPLOY_PREFLIGHT_ONLY=1 runs just this check (used for testing).
echo "🛫 Pre-flight checks..."
preflight_env_check || exit 1
if [[ "${DEPLOY_PREFLIGHT_ONLY:-}" == "1" ]]; then
    exit 0
fi
echo ""

# Step 1: Pull latest code
echo "📥 Pulling latest code from dev-dmytro..."
git pull origin dev-dmytro
echo ""

# Step 2: Install backend dependencies
echo "📦 Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader
echo ""

# Boot smoke test: a fresh vendor/ + new code must boot the app (this also trips
# the fatal boot guards in AppServiceProvider). Fails the deploy BEFORE migrations
# and worker restarts, so a broken release never replaces the running workers.
echo "🩺 Boot smoke test..."
if ! $PHP_BIN artisan about --only=environment >/dev/null; then
    echo "❌ Application failed to boot after pull + composer install." >&2
    echo "   Aborting before migrations and restarts; running workers are untouched." >&2
    exit 1
fi
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
# Determine supervisor program names based on the script location (stage vs. production)
case "$(basename "$SCRIPT_DIR")" in
    trackflow)
        DEPLOYMENT_ENV=production
        OCTANE_PROGRAM=trackflow-octane
        QUEUE_PROGRAM=trackflow-queue
        SCHEDULER_PROGRAM=trackflow-scheduler
        ;;
    stage-trackflow)
        DEPLOYMENT_ENV=staging
        OCTANE_PROGRAM=trackflow-stage-octane
        QUEUE_PROGRAM=trackflow-stage-queue
        SCHEDULER_PROGRAM=trackflow-stage-scheduler
        ;;
    *)
        echo "❌ Unrecognized deployment directory: $(basename "$SCRIPT_DIR")" >&2
        exit 1
        ;;
esac

echo "🔍 Detected deployment environment: $DEPLOYMENT_ENV"
echo ""

echo "♻️ Restarting Octane workers..."

if $PHP_BIN artisan octane:reload; then
    echo "✅ Octane workers reloaded gracefully."
elif command -v supervisorctl >/dev/null 2>&1; then
    echo "⚠️  octane:reload unavailable, falling back to supervisorctl restart."
    # Verify the octane program exists before attempting restart
    STATUS_OUTPUT=$(sudo supervisorctl status "${OCTANE_PROGRAM}:*" 2>&1) || true
    if echo "$STATUS_OUTPUT" | grep -qi "no such process\|no such group"; then
        echo "❌ Octane program '${OCTANE_PROGRAM}' not configured in supervisor."
        echo "   Octane workers were NOT restarted — stale Vite manifest may still be served."
    else
        sudo supervisorctl restart "${OCTANE_PROGRAM}:*"
    fi
else
    echo "❌ Neither octane:reload nor supervisorctl are available."
    echo "   Octane workers were NOT restarted — stale Vite manifest may still be served."
fi
echo ""

echo "♻️ Restarting queue workers..."
if command -v supervisorctl >/dev/null 2>&1; then
    # Check if the queue program exists by examining supervisorctl output.
    # supervisorctl status returns nonzero exit code both when the program is not
    # registered AND when it exists but is stopped/failed. We distinguish by
    # examining stderr/stdout for "no such process" or "no such group" errors.
    STATUS_OUTPUT=$(sudo supervisorctl status "${QUEUE_PROGRAM}:*" 2>&1) || true
    if echo "$STATUS_OUTPUT" | grep -qi "no such process\|no such group"; then
        echo "⚠️  Queue worker program '${QUEUE_PROGRAM}' not configured in supervisor."
        echo "   Skipping queue restart (staging may not have dedicated queue workers)."
    else
        sudo supervisorctl restart "${QUEUE_PROGRAM}:*"
    fi
else
    echo "⚠️  supervisorctl not available — queue workers were NOT restarted."
fi
echo ""

echo "♻️ Restarting scheduler..."
if command -v supervisorctl >/dev/null 2>&1; then
    # Check if the scheduler program exists by examining supervisorctl output.
    # supervisorctl status returns nonzero exit code both when the program is not
    # registered AND when it exists but is stopped/failed. We distinguish by
    # examining stderr/stdout for "no such process" or "no such group" errors.
    STATUS_OUTPUT=$(sudo supervisorctl status "${SCHEDULER_PROGRAM}:*" 2>&1) || true
    if echo "$STATUS_OUTPUT" | grep -qi "no such process\|no such group"; then
        echo "⚠️  Scheduler program '${SCHEDULER_PROGRAM}' not configured in supervisor."
        echo "   Skipping scheduler restart (scheduler may not be installed yet)."
    else
        sudo supervisorctl restart "${SCHEDULER_PROGRAM}:*"
    fi
else
    echo "⚠️  supervisorctl not available — scheduler was NOT restarted."
fi
echo ""

# Step 7: Purge Cloudflare cache
echo "☁️ Purging Cloudflare cache..."

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
