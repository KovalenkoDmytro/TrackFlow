# Laravel Octane Deployment — TrackFlow

This directory now contains all configuration and documentation needed to deploy Laravel Octane with FrankenPHP on the TrackFlow server.

## Files You Need

### 1. Configuration Files (Ready to Deploy)

| File | Purpose | Server Path |
|------|---------|-------------|
| `composer.json` | Added `laravel/octane` dependency | Commit & push to git |
| `config/octane.php` | Octane configuration | Already in project root |
| `supervisor-trackflow-octane.conf` | Octane supervisor config (production) | `/etc/supervisor/conf.d/` |
| `supervisor-trackflow-stage-octane.conf` | Octane supervisor config (staging on port 8071) | `/etc/supervisor/conf.d/` |
| `supervisor-trackflow-queue.conf` | Queue worker supervisor config (production) | `/etc/supervisor/conf.d/` |
| `.env` (edit on server) | Environment variables | `/home/malma/trackflow/.env` (production) or `/home/malma/stage-trackflow/.env` (staging) |
| `nginx-octane-config.txt` | nginx reverse proxy config | Reference for `/etc/nginx/sites-available/` |

### 2. Documentation Files (For Reference)

| File | Contains |
|------|----------|
| `OCTANE_SETUP.md` | Detailed step-by-step setup guide (11 KB) |
| `DEPLOYMENT_CHECKLIST.md` | Checklist format with all commands (8 KB) |
| `QUICK_COMMANDS.sh` | All bash commands grouped by phase (executable reference) |
| `README_OCTANE_DEPLOYMENT.md` | This file |

## Quick Start (3 Steps)

### Step 1: Commit & Push Locally

```bash
cd /Users/dmytrokovalenko/Documents/Projects/Growme/TrackFlow
git add composer.json config/octane.php
git commit -m "chore: add Laravel Octane configuration with FrankenPHP server"
git push origin main
```

### Step 2: SSH to Server & Pull

```bash
ssh malma@trackflow.dmytro-kovalenko.ca
cd /home/malma/trackflow
git pull origin dev-dmytro
composer install --no-dev --optimize-autoloader
```

> **Gotcha (learned the hard way):** never write `php /usr/bin/php8.4 ...` — that runs `php`
> with `/usr/bin/php8.4` as the *script argument*, i.e. PHP tries to parse the compiled
> `php8.4` binary as source code (`PHP Parse error: unexpected character 0x00 ...`). Call
> the binary directly: `/usr/bin/php8.4 artisan ...`. `composer` is its own executable on
> `$PATH` (`/usr/local/bin/composer` — confirm with `which composer`) — invoke it bare,
> never as an argument to `php8.4`.
>
> Also: there is **no `main` branch on the remote** — the server tracks `dev-dmytro`. Use
> `git pull origin dev-dmytro`, not `git pull origin main`.

### Step 3: Follow DEPLOYMENT_CHECKLIST.md

All remaining steps are in `DEPLOYMENT_CHECKLIST.md`. Copy-paste each command section.

## Staging Environment Setup (Optional)

If you have a separate staging deployment at `/home/malma/stage-trackflow`, follow the same Quick Start steps above, **but in Step 3 also deploy the staging supervisor config:**

### Pre-Deployment Setup (One-Time)

Before installing the supervisor config, ensure the staging directory has correct permissions:

```bash
# On the staging server:
# If /home/malma/stage-trackflow is owned by malma:malma, grant www-data write access:
sudo chown -R malma:www-data /home/malma/stage-trackflow/{storage,bootstrap/cache}
sudo find /home/malma/stage-trackflow/{storage,bootstrap/cache} -type d -exec chmod 2775 {} +
sudo find /home/malma/stage-trackflow/{storage,bootstrap/cache} -type f -exec chmod 0664 {} +

# Verify /var/log/supervisor exists and has correct permissions:
ls -la /var/log/supervisor/
# Expected: drwxr-xr-x root root (or similar root-owned directory)
```

### Deploy Supervisor Config

```bash
# On the staging server:
sudo install -o root -g root -m 0644 supervisor-trackflow-stage-octane.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start 'trackflow-stage-octane:*'
```

The staging Octane process listens on **port 8071** (vs. 8000 in production) and runs under the `www-data` user (same as production, for consistency and security). The `deploy.sh` script automatically detects whether it is running in the production or staging directory and uses the correct supervisor program names (`trackflow-stage-octane` vs. `trackflow-octane`).

**Note:** If staging does not require dedicated queue workers, you may omit the `supervisor-trackflow-queue.conf` setup — `deploy.sh` will skip queue restart if the program is not registered with supervisor.

## Architecture Overview

```
┌─────────────────────────────────────────┐
│         nginx (reverse proxy)           │
│   Port 443 (HTTPS, TLS 1.2/1.3)        │
└──────────────────┬──────────────────────┘
                   │ proxy_pass
                   ▼
┌─────────────────────────────────────────┐
│    Octane + FrankenPHP (Port 8000)      │
│  • 4 workers (auto = cpu_count * 4)    │
│  • Max 500 requests per worker          │
│  • Deployed via Supervisor              │
└─────────────────────────────────────────┘
                   │
        ┌──────────┴──────────┐
        ▼                     ▼
    ┌─────────┐         ┌──────────────┐
    │PostgreSQL       │Redis 7.2+     │
    │(Database)       │(Cache/Session)│
    └─────────┘       └──────────────┘
                   │
┌──────────────────┴─────────────────────┐
│ Supervisor Queue Workers (4 processes) │
│  • artisan queue:work --timeout=120   │
│  • Max 500 jobs per process/hour      │
│  • Automatic restart on failure       │
│  • User: malma                        │
└──────────────────────────────────────┘
```

## Key Changes

### What's Different From PHP-FPM

| Aspect | PHP-FPM | Octane/FrankenPHP |
|--------|---------|------------------|
| **Server** | Standalone daemon per request | Long-lived process (workers) |
| **Workers** | Handled by nginx | Explicit worker pool |
| **Startup** | `php-fpm.service` | Supervisor → `artisan octane:start` |
| **Memory** | Fresh per request | Persistent (requires cleanup) |
| **Performance** | ~50-100 req/sec | ~500-1000 req/sec |
| **nginx role** | FastCGI gateway | Reverse proxy (HTTP) |

### Config Files Changed

**Local changes (committed to git):**
- `composer.json` — Added `"laravel/octane": "^3.0"`
- `config/octane.php` — New file with Octane settings

**Server changes (manual via SSH):**
- `/etc/supervisor/conf.d/supervisor-trackflow-octane.conf` — New
- `/etc/supervisor/conf.d/supervisor-trackflow-queue.conf` — New
- `/etc/nginx/sites-available/trackflow.dmytro-kovalenko.ca` — Modified (location block)
- `/home/malma/trackflow/.env` — Add OCTANE_* variables

## Monitoring & Operations

### Daily Operations

**Production:**
```bash
# Check status
sudo supervisorctl status 'trackflow-octane:*' 'trackflow-queue:*'

# Tail logs (from root-owned supervisor log directory)
sudo tail -f /var/log/supervisor/trackflow-octane.log
sudo tail -f /var/log/supervisor/trackflow-queue.log

# Monitor queue depth
/usr/bin/php8.4 /home/malma/trackflow/artisan queue:monitor
```

**Staging:**
```bash
# Check status
sudo supervisorctl status 'trackflow-stage-octane:*'

# Tail logs (from root-owned supervisor log directory)
sudo tail -f /var/log/supervisor/trackflow-stage-octane.log

# Verify staging is listening on port 8071
sudo ss -tlnp | grep 8071
```

### Deployments (With Zero Downtime)

#### Quick Deploy (Recommended)

Use the included `deploy.sh` script to automate the entire process:

```bash
# Production:
cd /home/malma/trackflow
./deploy.sh

# Or staging:
cd /home/malma/stage-trackflow
./deploy.sh
```

The script will:
1. Pull latest code from `dev-dmytro`
2. Install PHP and npm dependencies
3. Build Vite frontend assets
4. Run database migrations
5. Clear framework caches
6. **Auto-detect the environment** (production vs. staging) and reload Octane workers (`octane:reload`, falling back to `supervisorctl restart` of the correct program: `trackflow-octane` for production, `trackflow-stage-octane` for staging)
7. Restart queue workers (if the program exists in supervisor; skipped for staging if not configured)
8. **Purge Cloudflare cache** (if configured, optional — see below)

#### Manual Deployment (If Preferred)

Alternatively, run the steps manually:

**Production:**
```bash
cd /home/malma/trackflow
git pull origin dev-dmytro
composer install --no-dev --optimize-autoloader
npm install && npm run build
/usr/bin/php8.4 artisan migrate --force
/usr/bin/php8.4 artisan optimize:clear
/usr/bin/php8.4 artisan octane:reload || sudo supervisorctl restart 'trackflow-octane:*'
sudo supervisorctl restart 'trackflow-queue:*'
```

**Staging:**
```bash
cd /home/malma/stage-trackflow
git pull origin dev-dmytro
composer install --no-dev --optimize-autoloader
npm install && npm run build
/usr/bin/php8.4 artisan migrate --force
/usr/bin/php8.4 artisan optimize:clear
/usr/bin/php8.4 artisan octane:reload || sudo supervisorctl restart 'trackflow-stage-octane:*'
# Skip queue restart if not configured for staging
```

**Restarting Octane workers is mandatory after every deploy that touches
`resources/js/`.** Octane (FrankenPHP) keeps long-lived PHP worker processes
that cache the Vite manifest (`Illuminate\Foundation\Vite::$manifests`) in
memory for the lifetime of the worker — it is never refreshed automatically.
If the workers are not restarted after `npm run build`, they keep serving
HTML that references the previous (now-deleted) hashed asset filenames,
causing 404s on JS/CSS and a white screen, even though the new build exists
on disk. Prefer `octane:reload` (graceful, no dropped connections); fall back
to `supervisorctl restart` only if `octane:reload` isn't available.

#### Cloudflare Cache Purge (Optional)

Cloudflare is not the cause of stale-asset 404s on staging — HTML responses
are served with `Cache-Control: no-cache, private` and `cf-cache-status:
DYNAMIC`, so Cloudflare never caches the HTML entry point. The real cause of
stale-asset issues is long-lived Octane workers serving a cached Vite
manifest (see above). Purging Cloudflare is a harmless extra step for other
cached assets, not a fix for that problem.

To enable the optional automatic purge in `deploy.sh`:
1. Set `CLOUDFLARE_ZONE_ID` and `CLOUDFLARE_API_TOKEN` in `/home/malma/trackflow/.env`
2. Obtain these values from your Cloudflare dashboard (Zone ID is in Overview, API Token under My Profile → API Tokens)
3. Re-run deployments using `./deploy.sh`

`deploy.sh` picks these up automatically: it prefers shell/process environment
variables (e.g. set in a CI runner), and otherwise reads them directly from the
`.env` file described above — no extra export step is needed on the server.

If neither the shell environment nor `.env` has these values set, the deploy script will skip the purge step with a warning.

(`npm install && npm run build` is required whenever `resources/js/` changed — frontend is React/TS via Vite, built assets are served from `public/` by nginx.)

### Scaling Queue Workers

To run 8 queue workers instead of 4, edit `/etc/supervisor/conf.d/trackflow-queue.conf`:

```ini
numprocs=8
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart 'trackflow-queue:*'
```

## Important Notes

### ✅ Things That Work With Octane

- **Eloquent queries** — Full support
- **Redis caching** — Preferred
- **Database connections** — Pooled (recommended in config/octane.php)
- **File uploads** — Via `storage_path()`
- **Queue jobs** — Native support

### ⚠️ Gotchas (Must Avoid)

- **Static properties** — Don't use singletons; they persist across requests
- **Global state** — Clear between requests (Octane handles this, but watch out)
- **File-based sessions** — Use Redis or database instead
- **Circular dependencies** — More likely in long-lived processes
- **Memory leaks** — `max_requests=500` restarts workers periodically

### 🔧 Octane Configuration Tuning

If you hit issues, adjust `/home/malma/trackflow/config/octane.php`:

```php
return [
    'workers' => 4,           // Instead of 'auto' (fixed count)
    'max_requests' => 250,    // Restart workers more frequently (lower = more restarts)
    'memory_limit' => '256M', // If memory-constrained
];
```

## Troubleshooting

### Octane won't start

```bash
# Check supervisor logs
sudo tail -100 /var/log/supervisor/supervisord.log

# Try running manually (Production — note: www-data user):
sudo -u www-data /usr/bin/php8.4 /home/malma/trackflow/artisan octane:start --server=frankenphp

# Try running manually (Staging — note: www-data user):
sudo -u www-data /usr/bin/php8.4 /home/malma/stage-trackflow/artisan octane:start --server=frankenphp --port=8071
```

### nginx returns 502 Bad Gateway

1. Is Octane listening? `sudo ss -tlnp | grep 8000`
2. Check `/var/log/nginx/error.log`
3. Restart both: `sudo supervisorctl restart 'trackflow-octane:*'` && `sudo systemctl reload nginx`

### Queue jobs not processing

```bash
# Check supervisor status
sudo supervisorctl status 'trackflow-queue:*'

# Check Laravel logs
tail -50 /home/malma/trackflow/storage/logs/laravel.log

# Check failed jobs
/usr/bin/php8.4 /home/malma/trackflow/artisan queue:failed
```

### Out of memory errors

Lower `workers` or `max_requests` in supervisor config, then restart.

## Rollback Plan

If you need to revert to PHP-FPM:

```bash
# Stop Octane/queue
sudo supervisorctl stop 'trackflow-octane:*' 'trackflow-queue:*'
sudo rm /etc/supervisor/conf.d/supervisor-trackflow-octane.conf
sudo rm /etc/supervisor/conf.d/supervisor-trackflow-queue.conf
sudo supervisorctl reread && sudo supervisorctl update

# Revert nginx to PHP-FPM
sudo nano /etc/nginx/sites-available/trackflow.dmytro-kovalenko.ca
# Change 'proxy_pass http://127.0.0.1:8000' to 'fastcgi_pass unix:/var/run/php/php8.4-fpm.sock'

# Restart
sudo systemctl start php8.4-fpm
sudo systemctl reload nginx
```

## Resources

- **Laravel Octane Docs**: https://laravel.com/docs/octane
- **FrankenPHP Releases**: https://github.com/dunglas/frankenphp/releases
- **Supervisor Docs**: http://supervisord.org/configuration.html

## Support

If you encounter issues:

1. Check the `TROUBLESHOOTING` section in `OCTANE_SETUP.md`
2. Review supervisor and nginx logs
3. Verify all environment variables are set correctly
4. Test Octane manually on the server

Good luck!
