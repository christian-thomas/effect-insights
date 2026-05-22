# @effect-cmp/api

Laravel 13 backend for the Effect CMP. Provides:

- Admin REST API (Sanctum) consumed by `apps/admin`
- Multi-tenancy via `organisation_id` global scopes (no tenancy package)
- Cookie database + OpenCookieDatabase importer (driven by `packages/cookie-db-seed`)
- Banner config storage + publish-to-Cloudflare-KV
- Scan dispatch to Cloudflare Browser Rendering REST API
- Consent ingest endpoint (receives mirrored events from edge Worker)
- Nightly archive job: D1 hot → MySQL `consent_event_archive`
- Scheduled scans via Laravel scheduler + Horizon queues

## First-run

```bash
cd apps/api
composer install
# .env created by composer create-project; APP_KEY already generated
php artisan migrate --graceful
php artisan serve
```

## Stack

- **PHP 8.3+** (developed on PHP 8.4 via Herd)
- **Laravel 13.x** (currently 13.11+)
- **MySQL** in production, SQLite for local dev (Laravel's default)
- **Redis** for queues + cache
- **Laravel Horizon** for queue dashboards
- **Sanctum** for SPA auth
- **spatie/laravel-permission** for per-organisation roles

## What lives here (planned)

```
app/
  Models/          Organisation, Domain, BannerConfig, Scan, ScanFinding,
                   Cookie, ConsentEventArchive, Vendor, ...
  Concerns/        BelongsToOrganisation trait
  Http/
    Controllers/Api/
      Admin/       organisations, domains, scans, banner configs
      Internal/    consent mirror webhook, scan findings webhook
  Services/
    Cloudflare/    KV publishing, Browser Rendering dispatch
    CookieDb/      Pattern matcher, OCD importer
    ConsentArchive/ Nightly D1 → MySQL job
  Jobs/            DispatchScan, ArchiveConsentBatch, PublishBannerConfig
```

## Out of scope (deferred)

- IAB TCF v2.2 — schema headroom only, no implementation v1
- CCPA/CPRA — v2
- Self-serve signup + billing — v2
