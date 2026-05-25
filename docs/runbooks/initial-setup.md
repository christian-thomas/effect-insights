# Runbook — initial setup

Step-by-step list of everything that needs to exist before the platform
runs end-to-end. Use this when bringing up a new environment (fresh dev
machine, staging tenant, production cutover).

Sections progress front-to-back: external accounts → secrets → resources
to provision → per-app configuration → smoke checks. Skip a section if
you only need the feature it gates.

## 1. External accounts you need

| Provider | Purpose | Plan / scope |
| --- | --- | --- |
| **Cloudflare** | Workers + KV + R2 + D1 + Email Routing + DNS for `effectinsight.com` | Workers paid plan with **Browser Rendering** enabled (free tier won't have it — required for the scanner) |
| **Slack** *(optional)* | Alerts (unknown cookies, scan failures, accessibility violations) | A Slack workspace where you can create a custom app and a bot user OAuth token (`xoxb-…`) |
| **MySQL 9** | Local dev DB | Herd Services makes this one-click on macOS |
| **Redis** | Queues + cache + Horizon | Local Redis (Homebrew/Docker) is fine for dev |
| **Domain registrar** | `effectinsight.com` registration | Point NS records at Cloudflare so worker routes can attach |

## 2. Secrets to generate

All four are 32-byte hex strings via `openssl rand -hex 32`. Each is paired
between Laravel and the corresponding Cloudflare worker — rotation means
rotating both sides at once.

| Secret | Laravel env key | Cloudflare worker | Worker secret name | Purpose |
| --- | --- | --- | --- | --- |
| Consent ingest secret | `CONSENT_INGEST_SECRET` | consent worker | `LARAVEL_INGEST_SECRET` | Edge worker → Laravel mirror auth on `/api/internal/consent-events` |
| Banner config signer | `BANNER_CONFIG_SIGNING_KEY` | config worker | `BANNER_CONFIG_SIGNING_KEY` | HMAC over banner config JSON; worker refuses to serve mismatched signatures |
| Scanner secret | `SCANNER_WORKER_SECRET` | scanner worker | `SCANNER_SECRET` | Laravel → scanner worker auth header |
| Consent receipt signer | `CONSENT_RECEIPT_SIGNING_KEY` | *(none)* | *(none)* | HMAC over exported consent receipts; regulators verify offline |

Generate all four and stash in 1Password / Bitwarden / Vault — you'll paste
them into `apps/api/.env` AND set them via `wrangler secret put` per worker
below.

## 3. Cloudflare resources to provision

One-time per environment. Run from `apps/edge/` so wrangler picks up the
right `wrangler.*.toml`.

### 3.1 — KV namespace (banner config publish target)

```bash
wrangler kv namespace create effectinsight-banner-config-dev
# Note the returned `id` — paste into BOTH:
#   - apps/edge/wrangler.config.toml ([[kv_namespaces]] block, binding = "BANNER_CONFIGS")
#   - apps/api/.env (CF_KV_NAMESPACE_ID)
```

### 3.2 — R2 bucket (cmp.js bundle storage)

```bash
wrangler r2 bucket create effectinsight-bundles
# Then uncomment the [[r2_buckets]] block in apps/edge/wrangler.config.toml.
```

### 3.3 — D1 database (consent log hot store)

```bash
wrangler d1 create effectinsight-consent
# Paste the returned database_id into:
#   - apps/edge/wrangler.consent.toml ([[d1_databases]] block)
#   - apps/api/.env (CF_D1_DATABASE_ID)
```

### 3.4 — Apply D1 migrations

```bash
cd apps/edge
bun run db:migrate:consent          # remote (production)
bun run db:migrate:consent:local    # local wrangler dev
```

### 3.5 — Custom worker routes (production only)

Uncomment the `routes = [...]` blocks in each `wrangler.*.toml` and set
`custom_domain = true`. Cloudflare provisions the DNS automatically as
long as the zone is on the account.

| Worker | Production route |
| --- | --- |
| config | `assets.effectinsight.com/config/*`, `/loader.js`, `/cmp.*.js` |
| consent | `assets.effectinsight.com/consent`, `/rum` |
| scanner | `scanner.effectinsight.com/*` |

### 3.6 — Cloudflare API token

Create one combined token at <https://dash.cloudflare.com/profile/api-tokens>
with these scopes (for Laravel's `CF_API_TOKEN`):

- **Workers KV Storage:Edit** — banner config publish + key purge
- **D1:Edit** — nightly archive job reads + deletes from D1
- **Workers Scripts:Edit** *(only if `wrangler deploy` runs from CI with this token)*

For least-privilege production deploys, scope a separate
`CLOUDFLARE_MAIL_API_KEY` token to **Email Routing Send** only and set
`MAIL_MAILER=cloudflare`.

## 4. Deploy + secret-set each worker

```bash
cd apps/edge

# Deploy the workers
bun run deploy:config
bun run deploy:consent
bun run deploy:scanner

# Set per-worker secrets (each prompts for the value)
wrangler secret put BANNER_CONFIG_SIGNING_KEY --config wrangler.config.toml
wrangler secret put LARAVEL_INGEST_SECRET     --config wrangler.consent.toml
wrangler secret put SCANNER_SECRET            --config wrangler.scanner.toml
```

## 5. Per-app configuration

### `apps/api` — Laravel (the API)

1. Copy `.env.example` → `.env` and fill the blank values. Key groups:
   - **Identity:** `APP_KEY` (via `php artisan key:generate`), `APP_URL`, `FRONTEND_URL` (admin SPA origin).
   - **DB:** `DB_*` for MySQL.
   - **Sanctum SPA:** `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`.
   - **Cloudflare:** all four secrets from §2, plus `CF_ACCOUNT_ID`, `CF_API_TOKEN`, `CF_KV_NAMESPACE_ID`, `CF_D1_DATABASE_ID`, `BANNER_CDN_URL`, `SCANNER_WORKER_URL`.
   - **Queues + Horizon:** `QUEUE_CONNECTION=redis`, `REDIS_*`, `HORIZON_NAME`.
   - **Mail:** `MAIL_MAILER=cloudflare` + `CLOUDFLARE_MAIL_API_KEY` in production; `MAIL_MAILER=log` in dev.
   - **Passkeys (dev only):** `PASSKEY_ALLOWED_ORIGINS=http://localhost:3001` — leave empty in prod, HTTPS satisfies the default check.
   - **Slack (optional):** `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL`.
   - **A11y alert default:** `ACCESSIBILITY_ALERT_THRESHOLD=5`.

2. `composer install`
3. `php artisan migrate` — runs all migrations including `passkeys`, `user_invitations`, `accessibility_*`.
4. `php artisan banner-configs:re-sign` — only if you've rotated `BANNER_CONFIG_SIGNING_KEY`. No-op on a fresh setup.
5. Horizon: `php artisan horizon` (one process; supervisord-default + supervisord-scans defined in `config/horizon.php`).
6. Scheduler: in production, ensure cron runs `php artisan schedule:run` every minute. Drives:
   - 02:00 — purge expired consent events (per-org `consent_retention_days`)
   - 03:00 — D1 → MySQL consent archive
   - 04:00 — dispatch scheduled cookie scans
   - 05:00 — dispatch scheduled accessibility scans

### `apps/admin` — Nuxt SPA

1. `cp .env.example .env` if present; otherwise just create one with
   `NUXT_PUBLIC_API_BASE=http://localhost:8000` (dev) or your prod API origin.
2. `bun install` (root) — admin is a Bun workspace.
3. `bun --filter @effectinsight/admin run dev` — starts on `localhost:3001` so `PASSKEY_ALLOWED_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` line up.

### `apps/edge` — Cloudflare Workers

Already covered in §3 and §4. For local dev:

```bash
cd apps/edge
bun run dev   # spawns all three workers on 8787/8788/8789 with prefixed logs
```

### `apps/banner` — cmp.js (Vite)

No env config in dev — the local Vite preview serves on `:4173`. For production, the bundle is built and uploaded to the R2 bucket (`effectinsight-bundles`) and served via the config worker.

```bash
bun --filter @effectinsight/banner run build
# Then upload dist/loader.js and dist/cmp.<hash>.js to R2.
```

### `apps/docs` — VitePress

No env config — content-only. Builds to `apps/docs/.vitepress/dist`. Deploy via Cloudflare Pages to `docs.effectinsight.com`.

## 6. Smoke checks once you're up

```bash
# 1. Laravel responds and tenancy works
curl -i http://localhost:8000/up

# 2. Banner config worker (after publishing at least one banner)
curl -i https://assets.effectinsight.com/config/<known-public-key>
# Expect 200 with { config, country } envelope, or 503 invalid_signature
# if the key on either side has drifted.

# 3. Consent worker accepts events
curl -i -X POST https://assets.effectinsight.com/consent \
  -H 'Content-Type: application/json' \
  -d '{ … valid ConsentEvent JSON … }'
# Expect 202; then GET /api/consent-events in the admin SPA to see it
# mirrored across.
```

In the admin UI: sign in → invite a user → land on `/cookies` → upload
the example CSV → promote one row. If all four work, the core surface is
operational.

## 7. Optional alerting

Set both `SLACK_BOT_USER_OAUTH_TOKEN` (xoxb-…) and
`SLACK_BOT_USER_DEFAULT_CHANNEL` in `.env` to enable Slack alerts. No-op
silently when either is unset, so safe to leave empty in dev.

## 8. Rotating shared secrets later

Both sides have to match at all times, so rotation is a two-step deploy:

1. Add the new secret as a separate var (e.g. `BANNER_CONFIG_SIGNING_KEY_NEXT`) on both Laravel and the worker.
2. Update the worker to accept either old or new.
3. Switch Laravel to sign with the new key.
4. After in-flight KV records are re-signed, remove the old key.

Simpler in practice today: brief downtime + rotate both sides
simultaneously, then run `php artisan banner-configs:re-sign` to
re-stamp every KV record.
