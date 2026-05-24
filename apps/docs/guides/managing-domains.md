# Managing domains

A "domain" in Effect CMP is one host that embeds the banner. Each
domain has its own banner config, its own scan history, its own
consent log, and its own scheduled-scan cadence.

## Adding a domain

1. **Domains → Add domain** in the admin.
2. Enter the **FQDN** — `www.example.com`, `app.example.com`. Bare
   apex domains (`example.com`) are fine too.
3. Save. The domain is created with status `pending` and a generated
   **Public key** (`pk_…`).

The public key is what cmp.js uses to fetch the right config. Copy
it from the domain's detail page into your embed snippet — see
[Implementing the banner](./implementing-the-banner) and the
[per-stack install guides](./installing-on-a-website).

## Domain status

Three states, with different operational effects:

| Status    | What's true                                                                                                                                                                                                                                                                                                                                                                 |
| --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pending` | Domain exists but no banner is live. The banner editor can be used (draft + publish), and rows are written to `banner_configs`, but the published payload is **not** exposed to Cloudflare KV. cmp.js' `/config/{publicKey}` returns 404 and silently no-ops. Use this state while you're configuring before the embed snippet goes on the client site.                            |
| `active`  | Banner is live. The currently-published config is mirrored into Cloudflare KV; cmp.js fetches it. Scheduled scans run on cadence. Consent events flow in. This is the normal state.                                                                                                                                                                                          |
| `paused`  | Banner stops serving. Flipping to `paused` **deletes the KV record** so cmp.js' config fetch starts returning 404 immediately (no edge cache; CF KV writes are global within seconds). The Laravel-side rows stay intact — flipping back to `active` re-publishes the current config to KV unchanged. Existing consent cookies stay valid (visitors who already consented aren't disturbed); new visitors and re-prompts see no banner until you un-pause. |

To pause, edit the domain and set status manually. Flipping back to
`active` is the inverse — the current published config is restored to
KV automatically; you don't need to re-publish.

## Deleting a domain

::: warning This is destructive
Deleting a domain cascade-deletes:

- Its banner configs (every version — append-only history goes too)
- Its scans + scan findings
- **All consent events** for that domain

Consent records are typically retained for audit purposes; once
deleted, they're gone. If you need the records but want to stop
serving the banner, **pause the domain instead**.
:::

If you need to delete a domain that's been live, export the consent
log first. Two export paths, depending on whether you need a
regulator-verifiable record or just data:

- **Bulk CSV** — `GET /api/consent-events.csv?domain_id=...` (or the
  **Export CSV** button on the consent log filter bar). Streamed via
  DB cursor so even large logs don't OOM the API. Unsigned — fine for
  internal archives and BI, not for legal proof.
- **Signed receipts** — open the consent log, filter by the domain,
  download per-event or per-visitor bundles in JSON or PDF. These
  carry the HMAC signature regulators ask for.

Either way, run `php artisan consent-events:archive` first if you
suspect there are recent events still in D1 that haven't drained to
MySQL yet. The archive job rotates D1 → MySQL daily at 03:00 but
running it ad-hoc guarantees the CSV / receipt sees the latest hot
rows.

## Scan cadence

Per-domain — see [Triggering scans](./triggering-scans) for the
mechanics. Settable on the domain detail page from the **Scan cadence**
dropdown (`Weekly` / `Monthly` / `Never`).

## The public key

The `publicKey` (`pk_…`) is a stable identifier for the domain. It's:

- Generated server-side when you create the domain.
- Used by cmp.js' `data-domain-id` attribute on the embed script tag.
- Used by the edge worker as the KV lookup key (`/config/{publicKey}`).
- Used by the consent worker to associate each event with the right
  domain.
- Safe to expose publicly — it's not a secret. It just identifies the
  config to fetch. Tampering at the edge is prevented by HMAC-signing
  every published config.

If you accidentally publish the wrong key in production, it's not a
disaster — visitors just see no banner until you fix it. There's no
secret to rotate. Generate a new domain with the right key and
re-embed if needed.

## Multi-tenant boundaries

Every domain belongs to exactly one **organisation**. Users belong to
one or more organisations and have a role per organisation (currently
just `owner` — granular roles are coming).

If you switch organisations via the dropdown in the sidebar, the admin
re-fetches all data scoped to the new org. You won't see another org's
domains, scans, consent events, or cookie overrides under any
circumstances — the Laravel global scope filters every query
server-side regardless of which user is asking.

Cross-organisation operations (moving a domain from one org to
another) aren't supported in admin. Talk to your account manager if
you genuinely need this.
