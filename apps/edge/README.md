# @effectinsight/edge

Three Cloudflare Workers behind `assets.effectinsight.com` (and `scanner.effectinsight.com` for the scanner):

| Worker               | Path(s)                                                    | Local port | Backed by                          |
| -------------------- | ---------------------------------------------------------- | ---------- | ---------------------------------- |
| `effectinsight-config`  | `/config/:domainPublicKey`, `/loader.js`, `/cmp.[hash].js` | 8787       | KV (configs), R2 (bundles)         |
| `effectinsight-consent` | `/consent` (POST), `/rum` (POST)                           | 8788       | D1 (hot log) + Laravel mirror, native rate limiter |
| `effectinsight-scanner` | `/scan` (POST)                                             | 8789       | Browser Rendering (paid plan only) |

## How the workers connect

- **config worker** — reads from KV on every config fetch. Re-computes
  the HMAC-SHA-256 signature against `BANNER_CONFIG_SIGNING_KEY` and
  serves 503 `invalid_signature` if it doesn't match. Stops a
  compromised CF API token from publishing a tampered config that
  would silently re-categorise tracking cookies as `necessary`.
- **consent worker** — writes the event to D1 via `INSERT OR IGNORE`
  (idempotent so cmp.js' outbox retries are safe) AND fires-and-forgets
  a server-to-server POST to `LARAVEL_INGEST_URL` (`[vars]`-level, not
  a secret) authed via `LARAVEL_INGEST_SECRET`. Also accepts RUM
  beacons at `/rum`. Both endpoints sit behind the
  `CONSENT_RATE_LIMITER` binding — 60 req / 60 s per `cf-connecting-ip`,
  fail-open if the binding errors so legitimate consent never drops.
- **scanner worker** — receives shared-secret–authed POSTs from
  Laravel's `ScanRunner` and crawls the requested URLs via
  `@cloudflare/puppeteer` (the `BROWSER` binding). Captures
  `page.cookies()`, `script[src]`, `iframe[src]`, and network
  requests, returns a `ScannerResult` JSON.

## First-run

```bash
wrangler login

# Create the backing resources (one-time)
wrangler kv namespace create BANNER_CONFIGS
wrangler r2 bucket create effectinsight-bundles
wrangler d1 create effectinsight-consent

# Paste the IDs into wrangler.config.toml / wrangler.consent.toml
# Then apply the consent D1 migrations:
bun run db:migrate:consent              # --remote (prod)
bun run db:migrate:consent:local        # --local (wrangler dev)
```

## Local dev

Each worker runs in its own terminal on a dedicated port (the dev:\* scripts
set the port flags explicitly so they don't collide on 8787):

```bash
bun run dev:config       # http://127.0.0.1:8787
bun run dev:consent      # http://127.0.0.1:8788
bun run dev:scanner      # http://127.0.0.1:8789
```

cmp.js can target the local config worker via `data-cmp-base` and the local
consent worker via `data-cmp-consent-base`. See
`apps/banner/test/e2e/fixtures/host-local.html` for a ready-made test page.

## Tests

```bash
bun run test                  # vitest-pool-workers — in-process miniflare
bun run test:e2e:wrangler     # spawns wrangler dev and hits it over HTTP
```

The in-process tests cover behaviour; the wrangler-dev smoke runner
catches misconfigurations in the toml itself (typos, missing imports,
binding declarations that won't load in the real runtime). Keep both —
they cover different failure modes.

## D1 schema

Versioned migrations live in `migrations/`. The first migration
(`0001_consent_events.sql`) is idempotent — safe to apply against existing
production data. See `bun run db:migrate:consent[:local]` above.

## Worker secrets

Each worker has its own secret namespace:

```bash
wrangler secret put BANNER_CONFIG_SIGNING_KEY --config wrangler.config.toml
wrangler secret put LARAVEL_INGEST_SECRET     --config wrangler.consent.toml
wrangler secret put SCANNER_SECRET            --config wrangler.scanner.toml
```

Generate matching values with `openssl rand -hex 32` and mirror them into
the Laravel side (`apps/api/.env`):

| Worker secret                | Mirrors Laravel env                          |
| ---------------------------- | -------------------------------------------- |
| `BANNER_CONFIG_SIGNING_KEY`  | `BANNER_CONFIG_SIGNING_KEY` (same name)      |
| `LARAVEL_INGEST_SECRET`      | `CONSENT_INGEST_SECRET`                      |
| `SCANNER_SECRET`             | `SCANNER_WORKER_SECRET`                      |

Rotating any of these is a two-step deploy — see `cmp-handoff.md` for
the exact ordering. The cheaper option for the signing key is a brief
downtime + `php artisan banner-configs:re-sign` to overwrite every KV
record with a freshly-signed payload.

## Rate limiting

`wrangler.consent.toml` declares a CF native rate-limit binding under
`[[unsafe.bindings]]`:

```toml
[[unsafe.bindings]]
name = "CONSENT_RATE_LIMITER"
type = "ratelimit"
namespace_id = "1100"
simple = { limit = 60, period = 60 }
```

Defensive wrapper in the worker treats binding errors as fail-open
(consent must never be silently dropped). Tests inject a fake limiter
to exercise both the 429 and fail-open paths. Vitest-pool-workers'
miniflare doesn't emulate the binding directly.

## RUM endpoint

The consent worker also accepts `POST /rum` from cmp.js with the
beacon shape:

```json
{
  "type": "cmp_rum",
  "domainPublicKey": "pk_...",
  "paintMs": 23,
  "hadStoredConsent": false,
  "effectiveConnectionType": "4g",
  "deviceMemoryGb": 8
}
```

Emits a structured JSON log line per beacon — no D1 writes yet.
`wrangler tail effectinsight-consent --format=json | grep cmp_rum` for
live tailing during incidents, or Cloudflare Logpush to a data
warehouse for aggregation.
