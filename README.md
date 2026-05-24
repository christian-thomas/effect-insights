# Effect CMP

Effect Digital's white-label Consent Management Platform — a CookieBot/CookieYes replacement owned end-to-end.

## What's in this monorepo

```
apps/
  api/             Laravel 13 (PHP 8.3+) — admin API, scan dispatch, cookie DB
  admin/           Nuxt 4 + Tailwind 4 — admin dashboard
  banner/          Vanilla TS (Vite) — embeddable consent banner script
  edge/            Cloudflare Workers — config delivery + consent ingest

packages/
  types/           Shared TS types: BannerConfig, ConsentEvent, GcmSignals
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

Six processes for a true end-to-end loop. Each gets its own terminal so you
can see its logs separately:

| # | Terminal                                        | What it does                                                        |
| - | ----------------------------------------------- | ------------------------------------------------------------------- |
| 1 | Laravel (via Herd)                              | API at `http://api.cmp-project.test` (or `php artisan serve`)       |
| 2 | `bun --filter @effect-cmp/admin run dev`        | Nuxt admin at `http://localhost:3001`                               |
| 3 | `bun --filter @effect-cmp/banner run dev`       | `vite build --watch` → rebuilds `apps/banner/dist/cmp.js` on save   |
| 4 | `bun --filter @effect-cmp/banner run serve:local` | Static host at `http://127.0.0.1:4173/test/e2e/fixtures/host-local.html` |
| 5 | `bun --filter @effect-cmp/edge run dev:config`  | Config worker at `http://127.0.0.1:8787`                            |
| 6 | `bun --filter @effect-cmp/edge run dev:consent` | Consent worker at `http://127.0.0.1:8788`                           |

Optional 7th — scanner worker (`dev:scanner` → 8789). Skip it unless you're
actively iterating on the scanner; it needs the paid Browser Rendering plan
and can't run usefully against a local D1.

### Walkthrough — first time

1. Log into the admin (terminal 2) at `http://localhost:3001`. Create a
   domain, publish a banner config. Laravel writes the payload to the local
   wrangler KV via `CloudflareKvClient` so the local config worker can serve
   it on the next request.
2. Apply the consent D1 schema locally: `bun --filter @effect-cmp/edge run
   db:migrate:consent:local`.
3. Open `http://127.0.0.1:4173/test/e2e/fixtures/host-local.html` (terminal
   4). Replace `data-domain-id="pk_test_LOCAL_REPLACE_ME"` in the file with
   the `publicKey` from the domain you just created.
4. Hit reload. The banner fetches its config from the local config worker
   (8787), renders, and on accept/reject POSTs the consent event to the
   local consent worker (8788). The consent worker writes to local D1 and
   fires-and-forgets to Laravel's `/api/internal/consent-events`.
5. Back in the admin, `Consent log` (`/consent`) shows the event. Click the
   row, hit "Download receipt → JSON" to grab a signed regulator-ready
   artefact.

### Testing pieces in isolation

- **Banner UI only** — admin's banner editor has a live preview iframe on
  `xl+` screens that uses `data-cmp-preview` + `data-cmp-config` to skip the
  edge entirely. Best for iterating on layout, colours, copy.
- **Banner against prod** — `host-local.html` flipping `data-cmp-base` back
  to `https://assets.effectcmp.com` (and removing `data-cmp-consent-base`)
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
- `apps/edge/README.md`

## Reference

- Architecture & implementation plan: `~/.claude/plans/i-want-to-potentially-zesty-pie.md`
- Cloudflare Browser Rendering limits: https://developers.cloudflare.com/browser-rendering/platform/limits/
- OpenCookieDatabase (CC0 seed source): https://github.com/jkwakman/Open-Cookie-Database
