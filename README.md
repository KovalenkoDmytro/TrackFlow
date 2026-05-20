# TrackFlow

Server-side conversion tracking for Shopify stores. Delivers conversion events to Google Ads, Meta, TikTok, and GA4 via their respective server-side APIs, bypassing browser-level ad blockers and iOS/browser privacy restrictions.

## Architecture

Built on Laravel 13 with the Laravel Actions pattern -- no controllers, all business logic lives in `app/Actions/`.

```
app/
├── Actions/          # All business logic (Page Actions + Business Actions)
├── Data/             # Spatie Laravel Data DTOs (typed input/output)
├── Enums/            # Platform enum (google_ads, meta, tiktok, ga4)
├── Models/           # Eloquent models (no repositories)
└── Http/
    └── Requests/     # Form Requests (validation only)

database/
└── migrations/       # One file per schema change, always reversible
```

## Domain Model

| Model | Purpose |
|-------|---------|
| `Shop` | Shopify store with encrypted `access_token` and `pixel_secret` |
| `PlatformIntegration` | Per-platform credentials (encrypted) and settings per shop |
| `ConversionActionMapping` | Maps tracking event names to platform-specific action IDs |
| `TrackingEvent` | UUID-keyed incoming conversion event with all attribution signals |
| `PlatformDelivery` | Delivery attempt log per platform per event (queued/sent/failed/skipped) |

## Data Flow (planned)

```
Shopify Pixel / Webhook
        ↓
TrackingEvent (stored, deduplicated via idempotency_key)
        ↓
PlatformDelivery rows created (one per active integration)
        ↓
Queue jobs → Google Ads / Meta CAPI / TikTok Events API / GA4 MP
```

## Stack

- **Laravel 13** + PHP 8.4
- **PostgreSQL** -- primary database
- **spatie/laravel-data** -- typed DTOs
- **lorisleiva/laravel-actions** -- Actions as controllers and objects
- **kyon147/laravel-shopify** -- Shopify OAuth + webhook handling
- **Pest PHP** -- testing

## Setup

```bash
cp .env.example .env
# Configure DB_* and APP_KEY in .env
php artisan key:generate
php artisan migrate
```

## Platforms (Phase 0 -- scaffold only)

- Google Ads Enhanced Conversions
- Meta Conversions API
- TikTok Events API
- Google Analytics 4 Measurement Protocol
