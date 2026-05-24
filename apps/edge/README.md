# @effect-cmp/edge

Three Cloudflare Workers behind `assets.effectcmp.com` (and `scanner.effectcmp.com` for the scanner):

| Worker                | Path(s)                                                    | Local port | Backed by                          |
| --------------------- | ---------------------------------------------------------- | ---------- | ---------------------------------- |
| `effect-cmp-config`   | `/config/:domainPublicKey`, `/loader.js`, `/cmp.[hash].js` | 8787       | KV (configs), R2 (bundles)         |
| `effect-cmp-consent`  | `/consent` (POST)                                          | 8788       | D1 (hot log) + Laravel mirror      |
| `effect-cmp-scanner`  | `/scan` (POST)                                             | 8789       | Browser Rendering (paid plan only) |

## First-run

```bash
wrangler login

# Create the backing resources (one-time)
wrangler kv namespace create BANNER_CONFIGS
wrangler r2 bucket create effect-cmp-bundles
wrangler d1 create effect-cmp-consent

# Paste the IDs into wrangler.config.toml / wrangler.consent.toml
# Then apply the consent D1 migrations:
bun run db:migrate:consent              # --remote (prod)
bun run db:migrate:consent:local        # --local (wrangler dev)
```

## Local dev

Each worker runs in its own terminal on a dedicated port (the dev:* scripts
set the port flags explicitly so they don't collide on 8787):

```bash
bun run dev:config       # http://127.0.0.1:8787
bun run dev:consent      # http://127.0.0.1:8788
bun run dev:scanner      # http://127.0.0.1:8789
```

cmp.js can target the local config worker via `data-cmp-base` and the local
consent worker via `data-cmp-consent-base`. See
`apps/banner/test/e2e/fixtures/host-local.html` for a ready-made test page.

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
the Laravel side (`apps/api/.env`).
