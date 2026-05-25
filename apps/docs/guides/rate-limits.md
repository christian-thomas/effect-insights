# Rate limits

The edge endpoints that accept POSTs from cmp.js (`/consent` and `/rum`)
are rate-limited per visitor IP. This page documents what the limits
are, what visitors experience when they hit one, and what an operator
should do when 429s start showing up.

## The limits

| Endpoint        | Limit           | Window     | Keyed on   |
| --------------- | --------------- | ---------- | ---------- |
| `POST /consent` | **60 requests** | 60 seconds | Visitor IP |
| `POST /rum`     | **60 requests** | 60 seconds | Visitor IP |

The platform rate-limits per visitor IP. The two endpoints are counted
separately — hitting the `/consent` limit doesn't deny `/rum`, and
vice-versa.

## What a real visitor sees

A normal visitor produces **1-2 consent events per session** (one
accept / reject click, maybe a withdrawal). They are nowhere near the
limit, ever.

The cap is there to slow a script attacker hammering the endpoint, not
to shape legitimate traffic.

## When you'd hit one

- **A bot or scraper aggressively reloading pages on the same client
  site.** Each fresh page load with cmp.js produces (at most) one
  consent event and one performance beacon. 60 of either in 60 seconds
  means ~one reload every second from the same IP — well into bot
  territory.
- **A misbehaving client integration that calls `/consent` in a loop.**
  cmp.js itself doesn't; if you're hitting the limit from real users
  there's probably a bug in custom code interacting with cmp.js' public
  API.
- **Large shared NATs (corporate networks, mobile carriers).** Lots of
  real visitors behind one external IP can in theory add up. We haven't
  seen this in the wild yet; if you do, get in touch and we'll raise
  the limit for that range.

## What happens when the limit is hit

- The edge returns `HTTP 429 Too Many Requests` with `Retry-After: 60`.
- cmp.js' **outbox** kicks in for `/consent` failures: the event is
  queued in `localStorage` and retried on the next page load (best-
  effort, capped at 5 attempts per event). Visitors don't lose their
  consent — it's recorded locally and replayed on the next visit.
- Performance beacons are fire-and-forget; a 429 just drops the beacon.
  No visible impact on the visitor.

## Adjusting the limits

If a sustained legitimate spike (a shared-egress IP from a customer
VPN, say) needs the ceiling raised, ask support — it's a platform-side
change rather than something an operator flips in admin.

## Fail-open behaviour

If the rate-limiter itself errors, the edge treats the request as
**allowed**. The blast radius of fail-open is a brief abuse window;
fail-closed would silently lose legitimate consent events, which is
worse.

## Monitoring

Operators don't have a direct view of 429 counts today. If you're
seeing the symptoms downstream (consent events from a specific source
suddenly stop appearing), ask support for elevated-429 stats on the
affected domain.

If you see sustained 429 rates from one source above ~0.1% of total
traffic on a domain, investigate:

1. Pull the offending IPs (support can supply these).
2. If they're known bots or CI runners hitting the embed page, exempt
   them at your own edge / WAF — these requests shouldn't even reach
   the platform.
3. If they're real users, get in touch and we'll raise the limit for
   the range.
