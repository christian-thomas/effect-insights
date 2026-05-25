# @effectinsight/api

Laravel 13 backend for the Effect Insight. Provides:

- Admin REST API (Sanctum) consumed by `apps/admin`
- Public cookie-policy embed endpoint (`/api/cookie-policy/{publicKey}.html`)
  — unauthenticated, served as a self-contained iframe-friendly HTML
- Multi-tenancy via `organisation_id` global scopes (no tenancy package)
- Cookie database + OpenCookieDatabase importer (driven by `packages/cookie-db-seed`)
- Banner config storage + publish-to-Cloudflare-KV (HMAC-signed) +
  history/rollback
- Scan dispatch to a Cloudflare Browser Rendering worker
- Consent ingest endpoint (receives mirrored events from the edge worker)
- Signed consent receipts (per-event + per-visitor, JSON + PDF) and bulk
  CSV export of the consent log
- Slack notifications for unknown cookies + scan failures via
  `laravel/slack-notification-channel`
- Three scheduled commands via the Laravel scheduler + Horizon queues
  (see [Scheduler](#scheduler) below)

## First-run

```bash
cd apps/api
composer install
# .env created by composer create-project; APP_KEY already generated.

# Create the dev database (Herd MySQL on 127.0.0.1:3306, no password by default).
mysql -h 127.0.0.1 -uroot -e \
  "CREATE DATABASE IF NOT EXISTS effect_cmp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

php artisan migrate:fresh --seed
php artisan serve   # http://localhost:8000
```

## Scheduler

Four jobs fire daily off `routes/console.php`:

| Time (UTC) | Command                              | What it does                                              |
| ---------- | ------------------------------------ | --------------------------------------------------------- |
| 02:00      | `consent-events:purge`               | Hard-deletes MySQL consent events past each org's `consent_retention_days` window. NULL = retain forever. |
| 03:00      | `archive-consent-events`             | Drains D1 → MySQL `consent_events`, idempotent on UUID.   |
| 04:00      | `scans:dispatch-scheduled`           | Dispatches `RunScanJob` for every domain whose cookie scan cadence is due, staggered to avoid hammering the scanner. |
| 05:00      | `accessibility:dispatch-scheduled`   | Dispatches `RunAccessibilityScanJob` for every domain whose a11y scan cadence is due. 1h offset from cookie scans so they don't fight for Browser Rendering quota. |

Each is `->onOneServer()->withoutOverlapping()` so multi-server
deploys can't double-fire. Make sure `php artisan schedule:work` (dev)
or a system cron entry calling `php artisan schedule:run` (prod) is
wired up.

Ad-hoc commands:

```bash
php artisan banner-configs:re-sign            # re-publish every active banner config to KV
php artisan consent-events:archive            # force a D1 → MySQL drain outside the 03:00 slot
php artisan consent-events:purge              # force a retention-window purge outside the 02:00 slot
php artisan scans:dispatch-scheduled          # force the cookie scan-dispatch sweep
php artisan accessibility:dispatch-scheduled  # force the a11y scan-dispatch sweep
```

## Queues + Horizon

Background jobs (scans, eventual scheduled archive runs) drain through
Redis-backed queues, supervised by Laravel Horizon.

**Local dev:**

```bash
# Redis ships with Herd; if you need to start it manually:
herd start redis

# Run the worker. The dashboard is at http://localhost:8000/horizon
# (superuser-only in non-local environments — see HorizonServiceProvider).
php artisan horizon
```

`QUEUE_CONNECTION` defaults to `redis` in `.env.example`. Tests override
to `sync` via `phpunit.xml` so jobs run inline without needing a worker.

Two supervisors are pre-configured (see `config/horizon.php`):
- **supervisor-default** — `default` queue, 60s timeout, 3 tries
- **supervisor-scans** — `scans` queue, 600s timeout, 2 tries (room for
  multi-URL crawls). `RunScanJob` pins to this queue.

**Production:** run `php artisan horizon` under supervisord or systemd
so it restarts on crash. After deploys, `php artisan horizon:terminate`
asks the running master to drain + exit so the new code is picked up.

## Deploys

`apps/api/deploy.sh` is the canonical post-deploy hook. Wire it into
Forge / GitHub Actions / whatever runs after `git pull` on the box:

```bash
cd /home/forge/effectinsight/apps/api && ./deploy.sh
```

The script:

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force`
3. `php artisan banner-configs:re-sign` — re-publishes every banner
   config to CF KV with the current `BANNER_CONFIG_SIGNING_KEY`.
   Critical on the first deploy of HMAC signing (pre-existing KV
   records have no signature → config worker refuses to serve them)
   and after any rotation of the signing key.
4. `php artisan config:cache && route:cache && event:cache`
5. `php artisan horizon:terminate` — supervisor restarts Horizon
   under the new code.

Every step is idempotent; the script exits non-zero on any failure so
the pipeline marks the release as failed.

Default credentials (from `DatabaseSeeder`):
- `me@christianthomas.co.uk` / `password` — superuser, owner of "Effect Digital".

## Stack

- **PHP 8.3+** (developed on PHP 8.4 via Herd)
- **Laravel 13.x** (currently 13.11+)
- **MySQL 9** (Herd Services) for local dev; **SQLite in-memory** for `php artisan test` (override in `phpunit.xml`)
- **Redis** for queues + cache
- **Laravel Horizon** for queue dashboards
- **Sanctum** for SPA auth (email/password + passkeys)
- **`spatie/laravel-passkeys`** for WebAuthn / FIDO2 credentials
- **spatie/laravel-permission** for per-organisation roles
- **Cloudflare mail transport** (Laravel 13 native `cloudflare` driver) for outbound notifications
- **`laravel/slack-notification-channel`** for unknown-cookie + scan-failure alerts
- **`barryvdh/laravel-dompdf`** for consent-receipt PDF rendering

## What lives here

```
app/
├── Console/Commands/
│   ├── ArchiveConsentEvents.php
│   ├── DispatchScheduledScans.php
│   ├── PurgeConsentEvents.php
│   └── ResignBannerConfigs.php
├── Enums/              BannerLayout, BannerGranularity, ConsentEventType,
│                       CookieCategory, DomainStatus, ScanStatus, ...
├── Http/
│   ├── Controllers/Api/
│   │   ├── Auth/                Login, Logout, Me
│   │   ├── BannerConfigs/       Show + Diff + History + Rollback + Draft + Publish
│   │   ├── BannerPreview/       ShowBannerPreview (HTML iframe for admin preview)
│   │   ├── ConsentEvents/       List + ExportCsv + EventReceipt + VisitorReceipt
│   │   ├── CookieImports/       List + Promote + Reject (superuser)
│   │   ├── CookieOverrides/     List + Create + Update + Delete (per-org)
│   │   ├── CookiePolicies/      ShowCookiePolicy (JSON) + ShowPublicCookiePolicy (HTML)
│   │   ├── Cookies/             ListCookies (global canonical)
│   │   ├── Dashboard/           ShowDashboard
│   │   ├── Domains/             CRUD
│   │   ├── Internal/            IngestConsentEvent (edge-worker-only)
│   │   ├── Organisations/       CRUD + SwitchOrganisation
│   │   ├── ScanFindings/        ListReviewQueue
│   │   └── Scans/               List + Show + TriggerScan
│   ├── Requests/                FormRequests with persist() helpers
│   ├── Resources/               JSON resources mirroring packages/types
│   └── Middleware/              consent.ingest, org.current, superuser
├── Jobs/
│   └── RunScanJob.php           Pinned to the scans queue
├── Models/                      Eloquent — all UUID keys, all enum-casted
├── Notifications/
│   ├── ScanFailed.php           Slack on scan failure
│   └── UnknownCookieFound.php   Slack on uncategorised scan findings
├── Services/
│   ├── BannerConfig/            Signer (HMAC-SHA-256)
│   ├── BannerConfigService.php  Draft/publish/rollback + edge KV sync
│   ├── Cloudflare/              HttpCloudflareKvClient + Array fake
│   ├── ConsentArchive/          ArchiveConsentEventsService
│   ├── ConsentEvents/           Receipt builder + signer + purge service
│   ├── Cookies/                 CookieCategoryResolver + CookiePolicyBuilder
│   │                            + OpenCookieDatabaseImporter
│   ├── Notifications/           UnknownCookieAlertService + ScanFailureAlertService
│   └── Scanner/                 ScanRunner + HttpCfBrowserRenderingScanner
│                                + SitemapDiscoveryService + Array fakes
└── Support/                     BannerConfigPayloadBuilder, BannerConfigDiffer,
                                 MaterialChangeDetector, CurrentOrganisation
```

`routes/api.php` is namespace-aliased — every route reads as
`Api\Feature\Action` so the file stays scannable.

## Out of scope (deferred)

- IAB TCF v2.2 — schema headroom only, no implementation v1
- CCPA/CPRA — v2
- Self-serve signup + billing — v2
