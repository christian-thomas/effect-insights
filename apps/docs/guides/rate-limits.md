# Rate limits

The edge endpoints that accept POSTs from cmp.js (`/consent` and `/rum`)
are rate-limited per visitor IP. This page documents what the limits are,
what visitors experience when they hit one, and what an operator should
do when 429s start showing up in monitoring.

## The limits

| Endpoint        | Limit           | Window     | Keyed on           |
| --------------- | --------------- | ---------- | ------------------ |
| `POST /consent` | **60 requests** | 60 seconds | `cf-connecting-ip` |
| `POST /rum`     | **60 requests** | 60 seconds | `cf-connecting-ip` |

Both endpoints share the limiter (one Cloudflare native rate-limit
binding) keyed separately as `consent:<ip>` and `rum:<ip>`. Hitting one
doesn't deny the other.

## What a real visitor sees

A normal visitor produces **1-2 consent events per session** (one accept /
reject click, maybe a withdrawal). They are nowhere near the limit, ever.

The cap is there to slow a script attacker hammering the endpoint, not to
shape legitimate traffic.

## When you'd hit one

- **A bot or scraper aggressively reloading pages on the same client site.**
  Each fresh page load with cmp.js produces (at most) one consent event and
  one RUM beacon. 60 of either in 60 seconds means ~one reload every
  second from the same IP — well into bot territory.
- **A misbehaving client integration that calls `/consent` in a loop.**
  cmp.js itself doesn't; if you're hitting the limit from real users
  there's probably a bug in custom code interacting with cmp.js' public API.
- **Large shared NATs (corporate networks, mobile carriers).** Lots of
  real visitors behind one external IP can in theory add up. We haven't
  seen this in the wild yet; if you do, get in touch and we'll raise the
  limit for that range.

## What happens when the limit is hit

- The worker returns `HTTP 429 Too Many Requests` with
  `Retry-After: 60`.
- cmp.js' **outbox** kicks in for `/consent` failures: the event is
  queued in `localStorage` and retried on the next page load (best-effort,
  capped at 5 attempts per event). Visitors don't lose their consent —
  it's recorded locally and replayed on the next visit.
- RUM beacons are fire-and-forget; a 429 just drops the beacon. No
  visible impact on the visitor.

## Where the limits live

Configured in `apps/edge/wrangler.consent.toml`:

```toml
[[unsafe.bindings]]
name = "CONSENT_RATE_LIMITER"
type = "ratelimit"
namespace_id = "1100"
simple = { limit = 60, period = 60 }
```

Different limits per environment can be set by overriding in
`wrangler.consent.{env}.toml`. Hot-reload via `wrangler deploy` — no
downtime, no cache to drain.

## Fail-open behaviour

If the rate-limit binding itself errors (CF outage, misconfig), the worker
treats the request as **allowed**. The blast radius of fail-open is a
brief abuse window; fail-closed would silently lose legitimate consent
events, which is worse. There's a unit test locking this in.

## Monitoring

`wrangler tail effect-cmp-consent --format=json | grep '"status":429'`
streams 429s as they happen. For a stable signal, the JSON log lines
emitted by the `/rum` endpoint can be aggregated via Cloudflare Logpush
to your data warehouse.

If you see sustained 429 rates above ~0.1% of total traffic on a domain,
investigate before the next pen-test:

1. Pull the offending IPs from CF logs.
2. If they're known bots / CI runners hitting the embed page, exempt
   them via Cloudflare's standard bot management — these requests
   shouldn't even reach the worker.
3. If they're real users, raise the limit in the toml and redeploy.
