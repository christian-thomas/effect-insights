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
│   │   ├── AccessibilityRuleOverrides/ List + Upsert + Delete (per-domain rule muting)
│   │   ├── AccessibilityScans/   List + Show + Trigger
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
│   │   ├── Passkeys/            6 endpoints — WebAuthn register + authenticate + manage
│   │   ├── ScanFindings/        ListReviewQueue
│   │   └── Scans/               List + Show + TriggerScan
│   ├── Requests/                FormRequests with persist() helpers
│   ├── Resources/               JSON resources mirroring packages/types
│   └── Middleware/              consent.ingest, org.current, superuser
├── Jobs/
│   ├── RunAccessibilityScanJob.php  Pinned to the accessibility-scans queue
│   └── RunScanJob.php               Pinned to the scans queue
├── Models/                      Eloquent — all UUID keys, all enum-casted.
│                                Passkey subclasses spatie's with HasUuids.
├── Notifications/
│   ├── AccessibilityIssuesFound.php  Slack on a11y violations above threshold
│   ├── AccessibilityScanFailed.php   Slack on a11y scan failure
│   ├── ScanFailed.php           Slack on cookie scan failure
│   └── UnknownCookieFound.php   Slack on uncategorised cookie scan findings
├── Services/
│   ├── Accessibility/           AccessibilityScanRunner + HttpA11yScanner +
│   │                            Array fake — mirrors the cookie scanner stack
│   ├── BannerConfig/            Signer (HMAC-SHA-256)
│   ├── BannerConfigService.php  Draft/publish/rollback + edge KV sync
│   ├── Cloudflare/              HttpCloudflareKvClient + Array fake
│   ├── ConsentArchive/          ArchiveConsentEventsService
│   ├── ConsentEvents/           Receipt builder + signer + purge service
│   ├── Cookies/                 CookieCategoryResolver + CookiePolicyBuilder
│   │                            + OpenCookieDatabaseImporter
│   ├── Notifications/           UnknownCookieAlertService + ScanFailureAlertService
│   │                            + AccessibilityAlertService
│   └── Scanner/                 ScanRunner + HttpCfBrowserRenderingScanner
│                                + SitemapDiscoveryService + Array fakes
└── Support/                     BannerConfigPayloadBuilder, BannerConfigDiffer,
                                 MaterialChangeDetector, CurrentOrganisation
```

`routes/api.php` is namespace-aliased — every route reads as
`Api\Feature\Action` so the file stays scannable.

## Passkeys (WebAuthn)

Wired via `spatie/laravel-passkeys` ^1.7 (which itself wraps
`web-auth/webauthn-lib`). The package's stub migration uses
`$table->id()` so we ship our own UUID-keyed migration in
`database/migrations/2026_05_25_090000_create_passkeys_table.php`
to match the project rule.

- `App\Models\Passkey` subclasses the package's model and adds
  `HasUuids`. Registered under `config/passkeys.php` so the
  package's actions resolve it.
- `App\Models\User` implements
  `Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys` and uses the
  `InteractsWithPasskeys` trait.
- The six controllers under `app/Http/Controllers/Api/Passkeys/`
  drive the two-step ceremony (server stashes the WebAuthn
  challenge in the session; the browser response is validated
  against it on the follow-up POST).
- Resource: `PasskeyResource` exposes only `id` + `name` +
  `lastUsedAt` + `createdAt`. The `credential_id` + `data`
  columns are verification material and never serialised.
- Relying party: `app.url`'s host. Use the registrable domain (not
  a subdomain) so a passkey registered against
  `app.effectinsight.com` works across `api.` + `assets.` too.

### Lost-authenticator recovery

If an operator loses every authenticator they registered:

1. **Preferred**: have them sign in with their email + password
   and revoke / re-register from **Settings → Passkeys** in the
   admin SPA.
2. **Last resort** (no password remembered either): a sysadmin
   with DB access can clear their credentials:

   ```sql
   DELETE FROM passkeys WHERE authenticatable_id = '<user-uuid>';
   ```

   The next sign-in attempts with any of those credentials will
   fail; password sign-in still works (the row in `users` is
   untouched).

## Mail driver

`config/mail.php` declares the `cloudflare` mailer (transport:
`cloudflare`), and `config/services.php` provides the `account_id`
+ `key` pair Laravel's native transport needs.
`CLOUDFLARE_MAIL_API_KEY` is read first, falling back to
`CF_API_TOKEN` if unset so a single combined token still works in
simpler setups.

In production prefer a **dedicated** Cloudflare API token scoped
to Email Routing Send only — principle of least privilege so a
leaked mail token can't tamper with banner configs (KV) or the
consent log (D1).

## Out of scope (deferred)

- IAB TCF v2.2 — schema headroom only, no implementation v1
- CCPA/CPRA — v2
- Self-serve signup + billing — v2

## Accessibility scan engineering reference

Operator-level docs are in `apps/docs/guides/accessibility-scans.md`.
This section captures the engineer-only detail stripped from the
public guides.

### Pipeline overview

1. **URL discovery.** Shares `SitemapDiscoveryService` with the
   cookie scanner — fetches `/sitemap.xml`, falls back to
   `/robots.txt` `Sitemap:` directives, falls back to apex-only.
   Capped at 150 URLs per scan.
2. **Browser run.** The edge scanner worker exposes a `/scan-a11y`
   route. For each URL it navigates with a headless browser
   (`networkidle0`, 30s timeout), injects axe-core, runs it, returns
   the violations.
3. **Persistence.** Each violation lands as a row in
   `accessibility_findings`. Aggregate counters (total violations +
   `violations_by_impact` breakdown) are held on the parent scan so
   the list view doesn't re-aggregate per render.
4. **Alerting.** When a scan completes, the impact threshold (default
   `serious`) is checked and `AccessibilityIssuesFound` fires via the
   notification channel if anything at or above that level exists.

### Scheduler

The 05:00 UTC `accessibility:dispatch-scheduled` command walks every
domain whose cadence isn't `never` and dispatches a queued scan when
the interval has elapsed since the last one. Never-scanned domains
are placed on a deterministic day inside the cadence window (CRC32
of the domain UUID mod `staggerWindow`).

`RunAccessibilityScanJob` pins to the `accessibility-scans` Horizon
queue (600s timeout). The 05:00 cron is offset 1h from the cookie
scanner's 04:00 so the two don't fight for Cloudflare Browser
Rendering quota.

If a11y scans start starving cookie scans at scale, the next step is
a dedicated `effectinsight-accessibility-scanner` worker on a
separate Browser Rendering plan.

### API surface

All endpoints sit behind Sanctum SPA auth + the `org.current`
middleware. Tenant-scoped.

```
GET    /api/accessibility-scans                          List, paginated
GET    /api/accessibility-scans/{accessibilityScan}      Detail + findings
POST   /api/domains/{domain}/accessibility-scans         Manual trigger (202)
```

The list endpoint accepts `?domain_id=…`. The detail endpoint returns
findings already ordered by impact severity.

`PATCH /api/domains/{domain}` accepts `a11y_scan_cadence`.

```
GET /api/domains/{domain}/accessibility-trend?days=N
```

Returns `points[]` with `scanId`, `finishedAt`, `total`, and
`byImpact`. Reads stored `violations_by_impact` per scan rather than
recomputing from findings, so mute changes don't retroactively
rewrite history.

### Finding shape

```json
{
  "id": "uuid",
  "accessibilityScanId": "uuid",
  "ruleId": "color-contrast",
  "impact": "serious",
  "description": "Elements must have sufficient color contrast",
  "helpText": "How to fix...",
  "helpUrl": "https://dequeuniversity.com/rules/axe/4.x/color-contrast",
  "url": "https://example.com/about",
  "tags": ["wcag2aa", "wcag143"],
  "nodes": [
    {
      "target": ["#main > p.subhead"],
      "html": "<p class=\"subhead\" style=\"color: #ccc\">…</p>",
      "failureSummary": "Fix any of the following: Element has insufficient color contrast..."
    }
  ]
}
```

`impact` is one of `critical | serious | moderate | minor`.

### Alert threshold

Resolution order: per-org column `organisations.a11y_alert_threshold`
→ platform env `ACCESSIBILITY_ALERT_THRESHOLD` → `serious`.

```bash
ACCESSIBILITY_ALERT_THRESHOLD=critical   # only Critical alerts
ACCESSIBILITY_ALERT_THRESHOLD=serious    # default
ACCESSIBILITY_ALERT_THRESHOLD=moderate   # Moderate and worse
ACCESSIBILITY_ALERT_THRESHOLD=minor      # every violation
```

Per-org override via `PATCH /api/organisations/{uuid}` with
`{ "a11y_alert_threshold": "minor" }`.

### Alert scope

`organisations.a11y_alert_scope` is `all` (default) or `wcag_only`.
A finding counts as best-practice when it carries the
`best-practice` axe tag AND has no `wcag*` tag. Rules tagged with
both count as WCAG.

### Rule muting

`accessibility_rule_overrides` table backs both org-wide and
per-domain mutes. Muting affects:

1. The notification pipeline — muted rules drop out of the impact-
   threshold count.
2. The admin scan detail page — muted findings are tagged + dimmed.

Findings are NOT deleted; mute is a reporting layer.

### Cost considerations

axe-core takes 2–5s per page versus <2s for the cookie scanner. A
50-URL crawl is ~3 minutes — comfortably inside the 600s Horizon
timeout. The shared Browser Rendering quota with the cookie scanner
is the practical ceiling.

## Consent records engineering reference

Operator-level docs are in `apps/docs/guides/consent-records.md`.
This section captures the engineer-only detail.

### REST surface

Every receipt + listing path is also reachable from scripts and CI.
Tenant-scoped under Sanctum SPA auth.

```
GET  /api/consent-events                                   Paginated list — ?domain_id= ?anonymous_id= ?event_type= ?from= ?to=
GET  /api/consent-events.csv                               Streamed CSV bulk export (same filters)
GET  /api/consent-events/{event}/receipt?format=json|pdf   Single-event signed receipt
GET  /api/consent-events/receipts/by-anonymous-id?anonymous_id=…&domain_id=…&format=json|pdf
                                                           Per-visitor bundle receipt
```

CSV columns are stable wire-format:

```
id, occurred_at, domain_id, organisation_id, banner_config_version,
anonymous_id, event_type, country_code, categories, gcm_signals,
banner_text_hash, user_agent_hash, ip_hash, created_at
```

`categories` and `gcm_signals` JSON-encode into a single CSV cell
each. Stream via DB cursor so multi-million-row exports don't OOM.

### Receipt signing

Self-contained JSON receipts carry an HMAC-SHA-256 signature over
the canonical JSON (keys sorted recursively; `signature` +
`signatureAlgorithm` stripped before signing). Anyone holding
`CONSENT_RECEIPT_SIGNING_KEY` can re-compute and verify.

Verifying a receipt server-side:

```bash
RECEIPT=$(cat receipt-{event_id}.json)
SIG=$(echo "$RECEIPT" | jq -r '.signature')
BODY=$(echo "$RECEIPT" | jq 'del(.signature, .signatureAlgorithm)' \
  | jq --sort-keys --compact-output .)
EXPECTED=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$CONSENT_RECEIPT_SIGNING_KEY" -hex | awk '{print $2}')
[ "$SIG" = "$EXPECTED" ] && echo "valid" || echo "tampered"
```

`ConsentReceiptSigner` does the same server-side in PHP. The signing
key is separate from `BANNER_CONFIG_SIGNING_KEY` so rotating one
doesn't invalidate the other.

If the historical banner config row referenced by the event is gone,
the receipt falls back to the current published row with
`snapshotNote: "current_published"`.

### Retention

`organisations.consent_retention_days` (NULL = forever, positive
integer = age cutoff). The 02:00 UTC `consent-events:purge` command
hard-deletes anything past the threshold. Offset from the 03:00
archive job so they never race for the same rows. Idempotent on
re-run.

`php artisan consent-events:purge` runs it ad-hoc.

### Data stores

- **D1 (Cloudflare)** — hot store, bounded by the nightly archive
  job draining events older than ~90 days.
- **MySQL (Laravel)** — long-term archive, indefinite by default,
  bounded only by `consent_retention_days` if set.

## Cookie management engineering reference

Operator-level docs are in `apps/docs/guides/cookie-management.md`
and `apps/docs/guides/cookie-policy-generation.md`.

### REST surface

```
GET    /api/cookies                          Canonical database, paginated (read-only for everyone)
GET    /api/cookie-imports                   Pending imports (superuser)
POST   /api/cookie-imports                   Upload CSV (superuser, multipart/form-data)
POST   /api/cookie-imports/promote-all       Bulk-promote pending (superuser)
POST   /api/cookie-imports/{import}/promote  Promote one (superuser)
POST   /api/cookie-imports/{import}/reject   Reject one (superuser)

GET    /api/cookie-overrides                 Org's overrides
POST   /api/cookie-overrides                 Create
PATCH  /api/cookie-overrides/{override}      Update
DELETE /api/cookie-overrides/{override}      Delete

GET    /api/scan-findings/review             Unknown cookies (review queue)

GET    /api/cookie-policy/{publicKey}.html   Public, unauthenticated cookie policy iframe
GET    /api/domains/{domain}/cookie-policy   Tenant-scoped JSON variant
```

### Resolver

`CookieCategoryResolver` walks
`domain override → org override → canonical DB` and returns the first
match. Cookies that don't resolve land in the review queue.

### CSV import

```bash
curl -X POST https://api.effectinsight.com/api/cookie-imports \
  -H "Cookie: <superuser sanctum session>" \
  -F "file=@cookies.csv" \
  -F "sourceVersion=ocd-2025-01-15"
```

CSV headers: `namePattern,vendor,category,expiryHint`. Existing
patterns are skipped (idempotent). Duplicates within the same import
are deduplicated.

`php artisan cookies:import --file=ocd.csv` seeds via the same
pipeline.

### Override shape

```json
{
  "namePattern": "_ga_*",
  "category": "statistics",
  "domainId": null,
  "purpose": "Google Analytics 4 client",
  "justification": "Signed off by legal 2026-04-01"
}
```

`domainId: null` makes it org-wide. The HTML cookie-policy endpoint
caches at `public, max-age=3600, stale-while-revalidate=86400`.

## Banner config engineering reference

Operator-level docs are in `apps/docs/guides/banner-configuration.md`
and `apps/docs/guides/triggering-scans.md`.

### Diff endpoint

`GET /api/domains/{domain}/banner-config/diff` returns:

```json
{
  "data": {
    "hasChanges": true,
    "isMaterial": false,
    "isFirstPublish": false,
    "changes": [
      {
        "field": "colors",
        "category": "cosmetic",
        "previous": { "primary": "#2563eb" },
        "next":     { "primary": "#000000" },
        "reason": "Visual restyle only"
      }
    ]
  }
}
```

Useful for CI integrations gating on material changes. Classification
stays in lock-step with `MaterialChangeDetector` server-side.

### History + rollback

Every publish writes a new row to `banner_configs` rather than
overwriting:

```
GET  /api/domains/{domain}/banner-config/history
POST /api/domains/{domain}/banner-config/rollback   { "version": N }
```

Rollback copies a historic version's content into the draft. It
does NOT publish — the standard pending-changes panel and material-
classification flow apply before anything goes live.

### Material vs cosmetic classification

Material fields (re-prompt visitors):
`gcm_defaults.*`, `geo_rules.mode`, `geo_rules.countries`,
`behaviour.honourGpc`, `behaviour.autoDenyOnDismiss`,
`behaviour.crossSubdomainConsent`, `locales_enabled` (add/remove).

Cosmetic fields (visitors keep consent):
`layout`, `granularity`, `locale_default`, `colors.*`, `copy.*`,
`behaviour.rePromptAfterDays`.

## Scan engineering reference

Operator-level docs are in `apps/docs/guides/triggering-scans.md`.

```
GET    /api/scans                          List, paginated, ?domain_id= filter, ?per_page=
GET    /api/scans/{scan}                   Detail + findings + diff vs previous
POST   /api/domains/{domain}/scans         Manual trigger, 202 + queued Scan
```

Detail endpoint returns:

```json
{
  "scan": { "data": { "id": "uuid", "domainId": "uuid", "status": "completed",
    "urlsDiscovered": 12, "urlsScanned": 12, "startedAt": "...",
    "finishedAt": "...", "createdAt": "..." } },
  "findings": { "data": [ /* ScanFinding[] */ ] },
  "diff": { /* new/removed/categoryChanged vs previous, or null */ }
}
```

`POST /api/domains/{domain}/scans` enqueues `RunScanJob` on the
`scans` queue and returns 202 immediately.

## Organisations engineering reference

Operator-level docs are in `apps/docs/guides/organisations.md`.

### Schema

| Column                   | Default          | Purpose |
| ------------------------ | ---------------- | ------- |
| `name`                   | required         | Display name |
| `slug`                   | required, unique | URL-safe identifier |
| `consent_retention_days` | `NULL`           | Auto-purge cutoff. NULL = retain forever. 1..3650 days. |
| `notification_email`     | `NULL`           | Comma-separated alert recipients. |
| `a11y_alert_threshold`   | `NULL`           | Per-org override of the global a11y impact threshold. |
| `a11y_alert_scope`       | `all`            | `all` or `wcag_only`. |
| `settings`               | `NULL`           | Reserved JSON column. |

### REST surface

Superuser-only except `POST /api/organisations/switch`:

```
GET     /api/organisations                Paginated list (superuser)
POST    /api/organisations                Create (superuser)
GET     /api/organisations/{org}          Detail (superuser)
PATCH   /api/organisations/{org}          Update (superuser)
DELETE  /api/organisations/{org}          Hard delete + cascade (superuser)

POST    /api/organisations/switch         Switch current session's active org
```

`switch` body: `{ "organisation_id": "uuid" }`. Validates membership
before rebinding `current_organisation_id`.

### Validation

- `slug` unique
- `consent_retention_days` ∈ `[1, 3650]` or `null`
- `a11y_alert_threshold` ∈ `critical|serious|moderate|minor` or `null`
- `a11y_alert_scope` ∈ `all|wcag_only`
- `notification_email` up to 320 chars; addresses filtered through
  `Organisation::notificationEmails()` at read time so junk silently
  drops rather than failing validation.

### Multi-tenancy

`BelongsToOrganisation` trait + `ResolveCurrentOrganisation`
middleware enforce the boundary server-side.
`CurrentOrganisation` is the single source of truth — middleware
rebinds before any controller runs.

Tests use `Tests\TenantTestCase::actingAsMember($user, $org)`. Never
plain `actingAs()` for tenant-scoped feature tests — skips the
middleware chain and silent cross-tenant leaks become possible.

## User management engineering reference

Operator-level docs are in `apps/docs/guides/user-management.md`.

All endpoints superuser-only except invitation acceptance.

```
POST   /api/organisations/{org}/invitations                 Create invitation
GET    /api/organisations/{org}/invitations                 List pending invitations
DELETE /api/organisations/{org}/invitations/{invitation}    Revoke unaccepted invitation

GET    /api/organisations/{org}/users                       List active members
DELETE /api/organisations/{org}/users/{user}                Remove member

GET    /api/invitations/{token}                              Show invitation (no auth)
POST   /api/invitations/{token}/accept                       Accept (no auth) — { password, name }
```

Invitations expire after 7 days. `UserInvited` notification sends
the magic-link email via the configured mail driver. Accepted
invitations create the user + session and consume the token.

## Personal account engineering reference

Operator-level docs are in `apps/docs/guides/personal-account-settings.md`.

```
GET    /api/me                           Current session + user details
PATCH  /api/me                           Update name + email
POST   /api/me/password                  Change password — { current_password, password, password_confirmation }
```

Auth required (Sanctum). Scoped to the current user only.

## Passkeys REST surface

Operator-level docs are in `apps/docs/guides/auth.md`.

```
POST   /api/login                              Email + password sign-in
POST   /api/passkeys/authentication-options    Passkey login challenge (no auth)
POST   /api/passkeys/authenticate              Passkey login verify (no auth)
GET    /api/passkeys                           List current user's passkeys (auth)
POST   /api/passkeys/register-options          Registration challenge (auth)
POST   /api/passkeys                           Register a new passkey (auth)
DELETE /api/passkeys/{passkey}                 Revoke (auth, own only)
GET    /api/me                                 Current session (auth)
PATCH  /api/me                                 Update name + email (auth)
POST   /api/me/password                        Change password (auth)
POST   /api/logout                             End session (auth)
```

Two-step ceremony for registration:

1. `POST /api/passkeys/register-options` — server stashes the
   `PublicKeyCredentialCreationOptions` in the session, returns JSON.
2. Browser calls `navigator.credentials.create()` with those options.
3. `POST /api/passkeys` with `{ passkey: <browser response JSON>, name: "label" }`
   — server pulls the original challenge from the session and
   validates against it.

Authentication is the same shape but
`authentication-options` → `navigator.credentials.get()` → `authenticate`.

`PasskeyResource` exposes only `id`, `name`, `lastUsedAt`,
`createdAt`. `credential_id` and `data` are verification material —
never serialised.

Relying party: `app.url`'s host. Use the registrable domain (not a
subdomain) so a passkey registered against `app.effectinsight.com`
works across `api.` + `assets.` too.

## Notifications engineering reference

Operator-level docs are in `apps/docs/guides/notifications.md`.

### Slack config

Platform-wide env vars on the API server:

| Variable                         | What it does                                             |
| -------------------------------- | -------------------------------------------------------- |
| `SLACK_BOT_USER_OAUTH_TOKEN`     | Bot user OAuth token (`xoxb-…`) for the workspace |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | Channel name (e.g. `#cmp-ops`) or channel id             |

Slack app needs `chat:write` + `chat:write.public` scopes. Invite
the bot to the channel: `/invite @{your-bot}`. Empty vars = silent
(local dev + CI default).

### Mail driver

`config/mail.php` declares the `cloudflare` mailer (Laravel 13's
native transport). `config/services.php` provides the `account_id` +
`key` pair. Read order:
`CLOUDFLARE_MAIL_API_KEY` → `CF_API_TOKEN`.

Use a dedicated Cloudflare API token scoped to Email Routing Send
only — least privilege so a leaked mail token can't tamper with KV
banner configs or D1 consent logs.

Local: `MAIL_MAILER=log`. Production: `MAIL_MAILER=cloudflare`.

### RUM beacons

The banner POSTs a structured beacon to the consent worker's `/rum`
endpoint on every render. Stored as a single JSON log line via
Cloudflare Logpush. Beacon shape:

```json
{
  "type": "cmp_rum",
  "domainPublicKey": "pk_…",
  "paintMs": 23,
  "hadStoredConsent": false,
  "effectiveConnectionType": "4g",
  "deviceMemoryGb": 8
}
```

Live tail:

```
wrangler tail effectinsight-consent --format=json | grep cmp_rum
```

No dashboard yet — track `hadStoredConsent: true` (fast path,
typically <10ms) and `false` (cold render) p95s separately when
building one.

## Rate limits engineering reference

Operator-level docs are in `apps/docs/guides/rate-limits.md`.

Two Cloudflare native rate-limit bindings on the consent worker:

| Endpoint        | Limit | Window | Key                |
| --------------- | ----- | ------ | ------------------ |
| `POST /consent` | 60    | 60s    | `consent:<cf-connecting-ip>` |
| `POST /rum`     | 60    | 60s    | `rum:<cf-connecting-ip>`     |

Hitting one doesn't deny the other. Returns 429 with
`Retry-After: 60`. cmp.js' localStorage outbox queues `/consent`
failures (5 attempts max). RUM beacons are fire-and-forget.

Fail-open if the binding errors — blast radius of fail-open is a
brief abuse window; fail-closed would silently lose legitimate
consent events, which is worse. Locked in by a unit test.

Per-environment limit overrides live in the consent worker's
`wrangler.toml`. Bump and `wrangler deploy` to widen.

Monitoring: `wrangler tail effectinsight-consent --format=json | grep '"status":429'`
streams 429s live. Logpush to a data warehouse for aggregated trend.

## CSP engineering notes

Operator-level docs are in `apps/docs/guides/csp.md` and
`apps/docs/guides/implementing-the-banner.md`.

The banner reads its nonce in this order:

1. `<meta name="csp-nonce" content="…">` — preferred, framework-
   agnostic.
2. `document.currentScript.nonce` — DOM property of the embed tag.
   The HTML `nonce` attribute is wiped by the browser after parsing;
   the property survives.
3. No nonce — banner omits `nonce` on injected elements.

cmp.js stamps the resolved nonce onto every `<style>` and every
re-injected `<script>` it creates. Strict CSP without
`https://assets.effectinsight.com` in `script-src` + `connect-src`
will block the loader and the config fetch respectively.

## Internal ingest

`POST /api/internal/consent-events` (consent.ingest middleware) is
edge-worker-only — verified by an HMAC over the body. The edge
worker mirrors every D1 write to the API so MySQL stays in sync
even if the nightly archive job is delayed.
