# @effect-cmp/edge

Two Cloudflare Workers behind `assets.effectcmp.com`:

| Worker | Path(s) | Backed by |
|---|---|---|
| `effect-cmp-config` | `/config/:domainPublicKey`, `/loader.js`, `/cmp.[hash].js` | KV (configs), R2 (bundles) |
| `effect-cmp-consent` | `/consent` (POST) | D1 (hot log) + Laravel mirror |

## First-run

```bash
# Auth
wrangler login

# Create the backing resources
wrangler kv namespace create BANNER_CONFIGS
wrangler r2 bucket create effect-cmp-bundles
wrangler d1 create effect-cmp-consent

# Paste the IDs into wrangler.config.toml / wrangler.consent.toml
# Then:
pnpm run dev:config
pnpm run dev:consent
```

## D1 schema

The hot consent table — apply once with `wrangler d1 execute`:

```sql
CREATE TABLE consent_events (
  id TEXT PRIMARY KEY,
  domain_public_key TEXT NOT NULL,
  banner_config_version INTEGER NOT NULL,
  anonymous_id TEXT NOT NULL,
  categories_json TEXT NOT NULL,
  gcm_signals_json TEXT NOT NULL,
  country_code TEXT,
  ua_hash TEXT NOT NULL,
  ip_hash TEXT NOT NULL,
  event_type TEXT NOT NULL,
  occurred_at TEXT NOT NULL,
  banner_text_hash TEXT NOT NULL
);

CREATE INDEX idx_consent_domain_time ON consent_events (domain_public_key, occurred_at);
CREATE INDEX idx_consent_anonymous_id ON consent_events (anonymous_id);
```
