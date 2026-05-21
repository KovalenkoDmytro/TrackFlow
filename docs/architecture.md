# TrackFlow — Architecture Guide

## Overview

TrackFlow is a Shopify app that performs server-side conversion tracking. When a
shopper triggers a storefront event (add to cart, checkout, purchase, etc.) the
Shopify Web Pixel fires a POST to TrackFlow's `/pixel/track` endpoint. TrackFlow
validates the payload, resolves the shop, and dispatches a background job that
forwards the event to every ad platform the merchant has connected (Google Ads,
Meta, TikTok, GA4).

Server-side tracking solves two problems with browser-side pixels: ad blockers
cannot intercept server requests, and the attribution data reaches the platform
even when the browser navigates away before the pixel fires.

## Request Lifecycle

```
Shopify Web Pixel
      │  POST /pixel/track  { event, gclid, value, ... }
      ▼
PixelController
  - Validates the HMAC signature (pixel_secret per shop)
  - Hydrates TrackingEventData (spatie/laravel-data DTO)
  - Dispatches ProcessTrackingEvent::dispatch($data)
      │
      ▼  (queue worker)
ProcessTrackingEvent::handle(TrackingEventData $data)
  - Resolves the User (shop) by shopDomain
  - PersistTrackingEvent::handle() → saves tracking_events record
      │   (record exists in DB even if all platform deliveries fail)
  - Loads all active PlatformIntegration records (eager-loads conversionActionMappings)
  - For each integration: calls processIntegration()
      │
      ├── PlatformDelivery::create (status: queued)
      │
      ├── PlatformResolverContract::resolve(Platform::GoogleAds)
      │       └── returns GoogleAdsClient
      │
      ├── ConversionPlatformContract::uploadConversion($credentials, $data, $mapping)
      │       └── GoogleAdsClient::uploadConversion()
      │               └── POST .../uploadClickConversions  →  Google Ads API
      │
      └── PlatformDelivery::update (status: delivered / partial_failure / failed)
```

Key points:
- The queue job retries up to 3 times with 30/60/120 second backoff on RuntimeException (network/auth failures).
- Partial failures (platform accepted the request but rejected the conversion data) return `false` and are recorded without triggering a retry — they represent data problems that would recur identically.
- `last_success_at`, `last_error`, and `last_error_at` on `PlatformIntegration` are updated after every attempt and surfaced in the settings UI.

## Adding a New Platform

Follow these three steps — no existing class requires modification.

### Step 1 — Implement ConversionPlatformContract

Create `app/Services/MetaConversionsClient.php` (or equivalent):

```php
final class MetaConversionsClient implements ConversionPlatformContract
{
    public function testCredentials(array $credentials): void { ... }
    public function setupConversionActions(PlatformIntegration $integration): void { ... }
    public function uploadConversion(array $credentials, TrackingEventData $data, ConversionActionMapping $mapping): bool { ... }
}
```

### Step 2 — Register in PlatformResolver

Add one case to the `match` in `app/Services/PlatformResolver.php`:

```php
Platform::Meta => $this->container->make(MetaConversionsClient::class),
```

### Step 3 — Update the settings UI

Add the new platform option to the settings form so merchants can connect it.
The backend (Actions, models, contracts) requires no changes.

## Key Design Decisions

**Why Laravel Actions (not Controllers + Services)?**
Each Action class has a single responsibility and can be dispatched either as an
HTTP controller (`AsController`) or a queue job (`AsJob`) without duplication.
`CreateConversionActions` and `ProcessTrackingEvent` use `AsJob` so they can be
dispatched from anywhere in the codebase and benefit from queue retries.

**Why ConversionPlatformContract?**
Without the interface, `ProcessTrackingEvent` and `CreateConversionActions` would
contain `if ($platform === Platform::GoogleAds) { ... } elseif ($platform === Platform::Meta) ...`
branches that grow with every new platform. The contract moves that branching into
`PlatformResolver` (one new match arm) and keeps the Actions stable
(Open/Closed Principle).

**Why PlatformResolverContract?**
Actions depend on the resolver interface, not `PlatformResolver` or any concrete
service class. This makes it trivial to bind a fake resolver in tests that returns
a stub platform without touching HTTP at all (Dependency Inversion Principle).

**Why encrypted credentials?**
`PlatformIntegration::credentials` is encrypted via Laravel's `encrypt()`/`decrypt()`
on every write/read. The column stores ciphertext, so database dumps, query logs,
and Telescope request history never expose OAuth tokens or developer tokens.
Platform drivers receive plain JSON — the encryption is transparent to them.

## Directory Map

```
app/
├── Actions/
│   ├── GoogleAds/
│   │   └── CreateConversionActions.php   — Job: provision conversion actions after platform connect
│   └── Tracking/
│       ├── PersistTrackingEvent.php      — Object: save raw event to tracking_events before dispatch
│       └── ProcessTrackingEvent.php      — Job: forward a storefront event to all active platforms
│
├── Contracts/
│   ├── ConversionPlatformContract.php    — Interface all platform drivers must implement
│   └── PlatformResolverContract.php      — Interface for resolving Platform enum → driver
│
├── Data/
│   └── TrackingEventData.php             — Immutable DTO: all attribution signals for one event
│
├── Enums/
│   └── Platform.php                      — GoogleAds | Meta | TikTok | Ga4
│
├── Http/Controllers/
│   ├── PixelController.php               — Receives Web Pixel webhooks, dispatches ProcessTrackingEvent
│   └── SettingsController.php            — Merchant settings UI (connect/disconnect platforms)
│
├── Models/
│   ├── User.php                          — The Shopify shop (implements ShopModel)
│   ├── PlatformIntegration.php           — Per-shop per-platform encrypted credentials + health fields
│   ├── ConversionActionMapping.php       — Maps a Shopify event name → platform external action ID
│   ├── TrackingEvent.php                 — Raw inbound event record; one per webhook POST
│   └── PlatformDelivery.php              — Audit record of one delivery attempt per integration
│
├── Providers/
│   └── AppServiceProvider.php            — Binds PlatformResolverContract → PlatformResolver
│
└── Services/
    ├── GoogleAdsClient.php               — ConversionPlatformContract impl for Google Ads REST API
    └── PlatformResolver.php              — Resolves Platform enum → ConversionPlatformContract via IoC
```
