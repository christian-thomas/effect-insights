# Runbook — Cloudflare outage

Where the CMP sits in the failure topology, what each kind of outage actually
breaks, and what to do about it. Owned by ops; mirror the contents here in
the on-call rotation tool.

## Where Cloudflare touches the stack

| Layer            | What we use it for                                       | Blast radius if it goes down                            |
| ---------------- | -------------------------------------------------------- | -------------------------------------------------------- |
| Workers          | `effect-cmp-config`, `effect-cmp-consent`, `effect-cmp-scanner` | All three endpoints 5xx; cmp.js' try/catch fails silently |
| KV (`BANNER_CONFIGS`) | Banner config payloads (HMAC-signed)                | Config fetch fails; cmp.js refuses to render the banner |
| R2 (`effect-cmp-bundles`) | Loader + hashed cmp.js bundle                  | Cold loads can't fetch the script; cached browsers unaffected |
| D1 (`effect-cmp-consent`) | Hot consent log (last ~90 days)                | Consent POSTs 500; cmp.js outbox queues + retries        |
| Browser Rendering | Scanner worker only                                    | Scheduled scans fail; next day's scheduler retries       |
| CDN / DNS         | Routing for `assets.effectcmp.com` and `scanner.effectcmp.com` | Total blackout: nothing reaches us                       |

cmp.js is wrapped in an outer `try/catch` (see `apps/banner/src/cmp.ts`) so a
broken endpoint never throws into the host page. Worst case the visitor sees
no banner, the host page renders normally, and the visitor's prior consent
(if any) stays in effect via the `_effectcmp` cookie.

## Per-failure decisions

### KV namespace is down (no banner config)

**Symptom**: `effect-cmp-config` returns 503 on `/config/:publicKey`. cmp.js
silently no-ops on every page load.

**Visitor impact**: New visitors don't see a banner. Repeat visitors with a
valid `_effectcmp` cookie still get GCM signals + script unblocking from the
cookie payload — they're unaffected. cmp.js' fail-silent design means the
host page never breaks.

**Action**:
1. Confirm via `wrangler tail effect-cmp-config` — look for KV errors.
2. Check CF status page (https://www.cloudflarestatus.com).
3. If CF-wide: nothing to do but wait. Update internal status.
4. If isolated: re-run `php artisan banner-configs:re-sign` from the Laravel
   side once KV is healthy to re-publish every banner config.

### D1 is down (consent POSTs failing)

**Symptom**: `effect-cmp-consent` returns 500 on `/consent`. cmp.js queues
the event in the localStorage outbox and retries on next page load.

**Visitor impact**: Their cookie is set, scripts unblock locally — the
client experience is fine. But the server-side log misses events.

**Action**:
1. Confirm via `wrangler tail effect-cmp-consent`.
2. Each visitor's next page load triggers `flushOutbox()` which retries up
   to 5x. Most events come through within a few minutes of D1 recovering.
3. Do NOT manually replay anything from the Laravel mirror — the worker is
   the canonical write path, not Laravel.

### R2 is down (cmp.js bundle 404)

**Symptom**: `loader.js` or `cmp.[hash].js` returns 404 from the config
worker. Browsers that have the bundle cached (immutable, max-age=31536000)
keep working.

**Visitor impact**: First-time visitors on uncached sessions see no banner.
Repeat visitors are fine because the bundle is in their browser cache for a
year.

**Action**:
1. Confirm via `wrangler tail effect-cmp-config`.
2. If isolated to one client domain: re-upload the bundle via
   `bun --filter @effect-cmp/banner run build` then push to R2.
3. If account-wide: wait. CF status.

### Workers runtime is down (everything 5xx)

**Symptom**: All three workers return 5xx or timeout. cmp.js silently
fails — its try/catch handles every external call.

**Visitor impact**: Same as the KV outage scenario — no new banners; repeat
visitors with cookies still work normally.

**Action**:
1. CF status. There's nothing to do at the application layer.
2. Update internal status; tell account managers.

### Browser Rendering is down (scanner only)

**Symptom**: `effect-cmp-scanner` returns 500 on `/scan`. Scheduled scans
fail; the daily `scans:dispatch-scheduled` command re-dispatches them
tomorrow per cadence + stagger.

**Visitor impact**: None. Scanner only powers the cookie discovery feature
in the admin.

**Action**:
1. `wrangler tail effect-cmp-scanner`.
2. No client-facing impact, no urgent action.

### DNS / total blackout

**Symptom**: `assets.effectcmp.com` doesn't resolve at all. Nothing reaches
us; cmp.js can't even load.

**Visitor impact**: First-time visitors on host pages with our snippet see
no banner. Their host page might log a console error for the failed script
fetch. cmp.js never executes so nothing crashes.

**Action**:
1. Check CF status + Cloudflare DNS dashboard.
2. If DNS records were unintentionally changed: `wrangler ...` deploys
   would have logged it; investigate via Workers dashboard audit log.

## Paging + status

- **CF status page**: https://www.cloudflarestatus.com — subscribe at the
  org level so on-call sees outages immediately.
- **Our status**: post in `#cmp-ops` (Slack) when degraded. If client-facing,
  email account managers with a one-line "we're tracking a Cloudflare
  incident affecting [X]; no action required from you."
- **Wrangler tail**:
  - `wrangler tail effect-cmp-config --format=json`
  - `wrangler tail effect-cmp-consent --format=json`
  - `wrangler tail effect-cmp-scanner --format=json`
- **CF Logpush**: forwards every worker's log to our centralised store —
  see the analytics dashboard for trend lines on 5xx rates.

## Things that DON'T need an on-call response

- Single failed consent POST → outbox handles it.
- One missed scheduled scan → next day's run picks it up.
- Single client reporting "banner didn't show" → likely a stored-consent
  cookie short-circuited; not an outage.

Only page on **sustained** elevated 5xx rates (>1% for >5 minutes) on any
worker, or a hard 503 from KV / D1 / R2 that persists past 60 seconds.
