# Runbook — Cloudflare outage

Where the CMP sits in the failure topology, what each kind of outage actually
breaks, and what to do about it. Owned by ops; mirror the contents here in
the on-call rotation tool.

## Where Cloudflare touches the stack

| Layer            | What we use it for                                       | Blast radius if it goes down                            |
| ---------------- | -------------------------------------------------------- | -------------------------------------------------------- |
| Workers          | `effectinsight-config`, `effectinsight-consent`, `effectinsight-scanner` | All three endpoints 5xx; cmp.js' try/catch fails silently |
| KV (`BANNER_CONFIGS`) | Banner config payloads (HMAC-signed)                | Config fetch fails; cmp.js refuses to render the banner |
| R2 (`effectinsight-bundles`) | Loader + hashed cmp.js bundle                  | Cold loads can't fetch the script; cached browsers unaffected |
| D1 (`effectinsight-consent`) | Hot consent log (last ~90 days)                | Consent POSTs 500; cmp.js outbox queues + retries        |
| Browser Rendering | Scanner worker only                                    | Scheduled scans fail; next day's scheduler retries       |
| CDN / DNS         | Routing for `assets.effectinsight.com` and `scanner.effectinsight.com` | Total blackout: nothing reaches us                       |

cmp.js is wrapped in an outer `try/catch` (see `apps/banner/src/cmp.ts`) so a
broken endpoint never throws into the host page. Worst case the visitor sees
no banner, the host page renders normally, and the visitor's prior consent
(if any) stays in effect via the `_effectinsight` cookie.

## Per-failure decisions

### KV namespace is down (no banner config)

**Symptom**: `effectinsight-config` returns 503 on `/config/:publicKey`. cmp.js
silently no-ops on every page load.

**Visitor impact**: New visitors don't see a banner. Repeat visitors with a
valid `_effectinsight` cookie still get GCM signals + script unblocking from the
cookie payload — they're unaffected. cmp.js' fail-silent design means the
host page never breaks.

**Action**:
1. Confirm via `wrangler tail effectinsight-config` — look for KV errors.
2. Check CF status page (https://www.cloudflarestatus.com).
3. If CF-wide: nothing to do but wait. Update internal status.
4. If isolated: re-run `php artisan banner-configs:re-sign` from the Laravel
   side once KV is healthy to re-publish every banner config.

### D1 is down (consent POSTs failing)

**Symptom**: `effectinsight-consent` returns 500 on `/consent`. cmp.js queues
the event in the localStorage outbox and retries on next page load.

**Visitor impact**: Their cookie is set, scripts unblock locally — the
client experience is fine. But the server-side log misses events.

**Action**:
1. Confirm via `wrangler tail effectinsight-consent`.
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
1. Confirm via `wrangler tail effectinsight-config`.
2. If isolated to one client domain: re-upload the bundle via
   `bun --filter @effectinsight/banner run build` then push to R2.
3. If account-wide: wait. CF status.

### Workers runtime is down (everything 5xx)

**Symptom**: All three workers return 5xx or timeout. cmp.js silently
fails — its try/catch handles every external call.

**Visitor impact**: Same as the KV outage scenario — no new banners; repeat
visitors with cookies still work normally.

**Action**:
1. CF status. There's nothing to do at the application layer.
2. Update internal status; tell account managers.

### Browser Rendering is down (both scanners)

**Symptom**: `effectinsight-scanner` returns 500 on `/scan` and `/scan-a11y`.
Both scheduled pipelines fail; the daily dispatch commands
(`scans:dispatch-scheduled` at 04:00 and
`accessibility:dispatch-scheduled` at 05:00) re-dispatch tomorrow per
cadence + stagger.

**Visitor impact**: None. Both scanners only power the discovery
features in the admin — cookie crawl results + axe-core findings.
Neither runs in the visitor request path.

**Slack noise**: Every failed scan fires its respective notification:
- Cookie scanner failures → `ScanFailed` via `ScanFailureAlertService`
- Accessibility scanner failures → `AccessibilityScanFailed` via
  `AccessibilityAlertService::alertOnFailure()`

During a sustained Browser Rendering outage with N cookie-scheduled
domains + M a11y-scheduled domains this is N+M alerts in a row —
**expected, ignore**. Don't mute the channel permanently; once
Browser Rendering recovers the alerts stop on their own at the next
scheduler run.

**Action**:
1. `wrangler tail effectinsight-scanner`.
2. No client-facing impact, no urgent action.

### Rate limiter throttling legitimate traffic

**Symptom**: `effectinsight-consent` returns 429 to a slice of `/consent` or
`/rum` POSTs. cmp.js' outbox retries — events eventually land — but the
short-term log dip shows up in BI dashboards.

**Visitor impact**: Cookie + GCM signals are local; client experience is
fine. The audit log lags by however long the retry window takes.

**Cause**: The `CONSENT_RATE_LIMITER` binding is 60 req / 60s **per
`cf-connecting-ip`**. Legitimate spikes (mobile carrier NAT, corporate
proxies sharing an egress IP) can trip it.

**Action**:
1. Confirm via `wrangler tail effectinsight-consent --format=json | grep 429`.
2. If the spike is from a known shared-egress IP (e.g. a client's office
   VPN), nothing to do — outbox retries will catch up.
3. If sustained from many IPs: raise the limiter ceiling in
   `apps/edge/wrangler.consent.toml` and `wrangler deploy --config
   wrangler.consent.toml`. The fail-open wrapper means the limiter
   binding errors never drop legitimate consent, so raising is safe.

### Laravel API outage (admin + cookie-policy embed)

**Symptom**: `api.effectinsight.com` is 5xx or timing out (Forge VPS down,
RDS flap, app exception, etc).

**Visitor impact on the consent flow**: **None at the edge.** cmp.js
talks only to the Cloudflare workers — banner config (KV), consent POST
(consent worker → D1), RUM, bundle fetch. None of these hit Laravel
synchronously. Even the consent worker's mirror to Laravel is
fire-and-forget; failures are silently dropped (D1 stays the canonical
write).

**Visitor impact on embedded cookie policies**: Sites that iframe
`https://api.effectinsight.com/api/cookie-policy/{publicKey}.html` will see
the iframe 5xx during the outage. Cloudflare's
`Cache-Control: public, max-age=3600, stale-while-revalidate=86400`
on the response keeps already-cached copies serving stale for up to 24
hours, so clients embedding via Cloudflare-fronted hostnames may not
notice short outages at all.

**Admin impact**: Operators can't log in or use the admin during the
outage. New banner publishes can't happen, scans can't be triggered.

**Passkey impact**: Both passkey registration and passkey sign-in go
through Laravel (`POST /api/passkeys/authentication-options` →
`POST /api/passkeys/authenticate`) so passkey login is offline for the
duration of the outage. There's no edge fallback — the WebAuthn
challenge is generated and validated server-side. Operators with an
existing session cookie stay signed in (Sanctum sessions are stored
in the Laravel-side DB; if MySQL is the failure mode they're logged
out on the next API call). Password sign-in is also down — both auth
methods share the same Laravel entrypoint.

**Scheduled jobs**: If MySQL or Laravel is down at 02:00 / 03:00 /
04:00 UTC:
- `consent-events:purge` (02:00) — fails for that day. Retention is a
  ceiling, not a floor — events older than the window persist one
  extra day. Re-run manually via
  `php artisan consent-events:purge` once Laravel recovers, or wait
  for the next 02:00 slot.
- `archive-consent-events` (03:00) — fails. D1 keeps growing until
  the job runs next; D1's storage cap is generous so this is fine for
  short outages. Run `php artisan consent-events:archive` ad-hoc to
  catch up.
- `scans:dispatch-scheduled` (04:00) — fails. Next day's run picks up
  every domain whose interval has elapsed (no events lost; just a
  day late).

**Action**:
1. Triage Laravel side first — Forge dashboard, RDS metrics, Sentry.
2. Once recovered, re-run the missed schedulers ad-hoc (commands
   listed above). All three are idempotent — safe to re-run.
3. If a domain was paused/unpaused via admin during the outage but
   the KV sync silently failed, run `php artisan banner-configs:re-sign`
   from the recovered Laravel side to reconcile.

### Bad banner config published (rollback lever)

**Symptom**: A publish made it through but it's wrong — broken copy,
material change that re-prompts every visitor unexpectedly, GCM
defaults that leak tracking.

**Visitor impact**: Whatever the bad config does. Could be ugly text,
re-prompt fatigue, or compliance leak depending on what changed.

**Action**:
1. Open the admin → domain → Banner config. The **Version history**
   panel below "Pending changes" lists every published version newest
   first.
2. Click "Roll back" on the version you want. The draft is overwritten
   with that version's content but is NOT auto-published.
3. Review the pending-changes panel — confirm the diff matches your
   intent — and click **Publish**. The new publish lands in KV
   immediately; cmp.js picks it up on the next config fetch (no edge
   cache; KV writes propagate within seconds globally).
4. The rolled-back version becomes the new current, and the previous
   bad publish stays in history (don't delete it — append-only is the
   audit guarantee).

If the rollback itself doesn't land because KV is the failure mode,
read the **KV namespace is down** section above first.

### DNS / total blackout

**Symptom**: `assets.effectinsight.com` doesn't resolve at all. Nothing reaches
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
  - `wrangler tail effectinsight-config --format=json`
  - `wrangler tail effectinsight-consent --format=json`
  - `wrangler tail effectinsight-scanner --format=json`
- **CF Logpush**: forwards every worker's log to our centralised store —
  see the analytics dashboard for trend lines on 5xx rates.

## Things that DON'T need an on-call response

- Single failed consent POST → outbox handles it.
- One missed scheduled scan → next day's run picks it up.
- Single client reporting "banner didn't show" → likely a stored-consent
  cookie short-circuited; not an outage.

Only page on **sustained** elevated 5xx rates (>1% for >5 minutes) on any
worker, or a hard 503 from KV / D1 / R2 that persists past 60 seconds.
