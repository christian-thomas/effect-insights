# Effect Insight

Effect Digital's white-label website-audit platform. Multi-module, multi-tenant,
owned end-to-end. Current modules:

- **CMP** — GDPR-ready consent banner + cookie scanner + signed audit log
  (CookieBot / CookieYes replacement).
- **Accessibility** — scheduled axe-core WCAG audits with Slack alerts.

Future modules (performance, SEO, security, …) plug into the same admin SPA,
organisations, domain registry, scheduler, and notification channel.

## What's in this monorepo

```
apps/
  api/             Laravel 13 (PHP 8.3+) — admin API, scheduled scans + a11y,
                   cookie DB, consent log
  admin/           Nuxt 4 + Pinia + Nuxt UI v4 — operator SPA
  banner/          Vanilla TS (Vite) — embeddable consent banner script
  docs/            VitePress — user-facing documentation site
  edge/            Cloudflare Workers — config delivery, consent ingest,
                   cookie + a11y scanners

packages/
  types/           Shared TS types + zod schemas
  cookie-db-seed/  OpenCookieDatabase importer → canonical cookie schema
```

## Stack

- **Bun 1.3+** (package manager + runtime), **Turborepo** for orchestration
- **Node 22+** (Nuxt, Wrangler, Vite still run on Node)
- **PHP 8.3+**, **Composer**, **Laravel 13** (Herd locally)
- **Cloudflare**: Workers, KV, D1, R2 (Wrangler for local dev)

## Prerequisites

- Bun 1.3+ (`bun --version`) — install via `curl -fsSL https://bun.sh/install | bash`
- Node 22+ (`node -v`)
- PHP 8.3+ via [Laravel Herd](https://herd.laravel.com/)
- Composer 2.x
- Cloudflare account + `wrangler login`

## First-run setup

```bash
bun install
cd apps/api && composer install
```

## Common scripts (root)

```bash
bun run dev          # turbo run dev (admin + banner watcher in parallel)
bun run build        # turbo run build
bun run check        # lint + typecheck across all packages
bun run test         # full test sweep
```

## Running the full stack locally

Two terminals for the happy path. Laravel runs in Herd; everything else
runs through turbo with prefixed logs so you can tell each process apart.

| # | Terminal                  | What it starts                                                                 |
| - | ------------------------- | ------------------------------------------------------------------------------ |
| 1 | Laravel (Herd)            | API at `http://api.cmp-project.test` (or `php artisan serve`)                  |
| 2 | `bun run dev` (root)      | Admin (3001) + banner build watcher + fixture host (4173) + 3 edge workers (8787/8788/8789) |

`bun run dev` at the root fans out via Turborepo to:
- `apps/admin` — `nuxt dev` on `http://localhost:3001`
- `apps/banner` — `vite build --watch` + static fixture host at `http://127.0.0.1:4173/test/e2e/fixtures/host-local.html`
- `apps/edge` — three `wrangler dev` workers (config 8787, consent 8788, scanner 8789) with prefixed `[config]` / `[consent]` / `[scanner]` log lines

### Running services on their own

When debugging a single piece (wrangler's output is noisy and prefix-merged
output can be fiddly to read), invoke the per-workspace scripts directly:

```bash
bun --filter @effectinsight/admin run dev
bun --filter @effectinsight/banner run dev:watch        # vite watcher only
bun --filter @effectinsight/banner run serve:local      # fixture host only
bun --filter @effectinsight/edge   run dev:config       # one worker only
bun --filter @effectinsight/edge   run dev:consent
bun --filter @effectinsight/edge   run dev:scanner
```

The scanner worker needs the paid Browser Rendering plan and can't run
usefully against a local D1, so skip it unless you're actively iterating
on the scanner.

### Walkthrough — first time

With Laravel running in Herd and `bun run dev` going at the root:

1. Log into the admin at `http://localhost:3001`. Create a domain, publish
   a banner config. Laravel writes the payload to the local wrangler KV
   via `CloudflareKvClient` so the local config worker can serve it on
   the next request.
2. Apply the consent D1 schema locally:
   `bun --filter @effectinsight/edge run db:migrate:consent:local`.
3. Open `http://127.0.0.1:4173/test/e2e/fixtures/host-local.html`. Replace
   `data-domain-id="pk_test_LOCAL_REPLACE_ME"` in the file with the
   `publicKey` from the domain you just created.
4. Hit reload. The banner fetches its config from the local config worker
   (8787), renders, and on accept/reject POSTs the consent event to the
   local consent worker (8788). The consent worker writes to local D1 and
   fires-and-forgets to Laravel's `/api/internal/consent-events`.
5. Back in the admin, `Consent log` (`/consent`) shows the event. Click
   the row, hit "Download receipt → JSON" to grab a signed regulator-ready
   artefact. The "Export CSV" button on the filter bar pulls the whole
   filtered list as a streamed download.
6. On the domain detail page (`/domains/<id>`) you'll see a Cookie Policy
   card once a scan has completed — it generates from the latest scan
   findings + canonical cookie DB. Copy the iframe snippet to embed the
   policy on the client's privacy page.

### Testing pieces in isolation

- **Banner UI only** — admin's banner editor has a live preview iframe on
  `xl+` screens that uses `data-cmp-preview` + `data-cmp-config` to skip the
  edge entirely. Best for iterating on layout, colours, copy.
- **Banner against prod** — `host-local.html` flipping `data-cmp-base` back
  to `https://assets.effectinsight.com` (and removing `data-cmp-consent-base`)
  lets you eyeball the production-served banner from a local page.
- **Consent worker only** — POST a sample event with `curl`:
  ```bash
  curl -X POST http://127.0.0.1:8788/consent \
    -H 'Content-Type: application/json' \
    -d @apps/banner/test/e2e/fixtures/sample-event.json   # example shape
  ```

### Required env

- `apps/api/.env` — see `.env.example`. Locally `CF_*` can stay blank since
  KV publishes go to wrangler-dev's in-memory store via the bound binding.
  `BANNER_CONFIG_SIGNING_KEY` + `CONSENT_RECEIPT_SIGNING_KEY` should match
  whatever you set via `wrangler secret put` (or local equivalents). Same
  for `CONSENT_INGEST_SECRET` ↔ the consent worker's `LARAVEL_INGEST_SECRET`.

## Per-app docs

See each app's own README:
- `apps/api/README.md`
- `apps/admin/README.md`
- `apps/banner/README.md`
- `apps/docs/README.md`
- `apps/edge/README.md`

## User-facing documentation

End-user docs (banner implementation, CSP setup, troubleshooting,
operations) are written in VitePress under `apps/docs/`. Run locally with
`bun --filter @effectinsight/docs run dev`, or visit the deployed site at
[docs.effectinsight.com](https://docs.effectinsight.com).

## Host-page integration surface

Once cmp.js is embedded, host pages can integrate via:

- **`window.cmp`** — installed eagerly on script load. 9 methods:
  `getConsent`, `isReady`, `ready`, `openBanner`, `withdraw`,
  `acceptAll`, `rejectAll`, `setConsent`, `onConsentChange`. Wire
  `cmp.withdraw()` to a "Cookie preferences" footer link for GDPR
  Article 7(3) compliance.
- **`cmp:consent-change` DOM event** on `document` — for Tag Manager
  style integrations that listen via `addEventListener` instead of
  importing modules.
- **`window.dataLayer`** GCM v2 signals — standard GTM/GA4 path.
- **`/api/cookie-policy/{publicKey}.html`** — drop into an iframe on
  the client's privacy page for an auto-updating cookie policy.

See `apps/docs/guides/javascript-api.md` and
`apps/docs/guides/cookie-policy-generation.md` for the full reference.

## Operations

Scheduled commands fire daily under Horizon (see `apps/api/routes/console.php`):

- **02:00** `consent-events:purge` — hard-deletes events past each
  org's `consent_retention_days` window. NULL = retain forever.
- **03:00** `archive-consent-events` — drains D1 → MySQL.
- **04:00** `scans:dispatch-scheduled` — dispatches `RunScanJob` for
  every domain whose cookie scan cadence is due.
- **05:00** `accessibility:dispatch-scheduled` — dispatches
  `RunAccessibilityScanJob` for every domain whose a11y scan cadence
  is due. 1h offset so the two scanners don't fight for Browser
  Rendering quota.

Slack alerts fire on unknown-cookie discoveries, cookie scan failures,
accessibility violations (Critical/Serious by default), and a11y
scan failures — see `apps/docs/guides/notifications.md`.

## Reference

- Architecture & implementation plan: `~/.claude/plans/i-want-to-potentially-zesty-pie.md`
- Cloudflare Browser Rendering limits: https://developers.cloudflare.com/browser-rendering/platform/limits/
- OpenCookieDatabase (CC0 seed source): https://github.com/jkwakman/Open-Cookie-Database
