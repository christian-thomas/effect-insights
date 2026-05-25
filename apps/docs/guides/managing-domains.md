# Managing domains

A "domain" in Effect Insight is one host that embeds the banner. Each
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

| Status    | What's true                                                                                                                                                                                                                                                                                                                              |
| --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pending` | Domain exists but no banner is live. You can draft and publish banner configs and the version history will accumulate, but the banner won't actually render anywhere yet. Use this while you're configuring before the embed snippet goes on the client site.                                                                            |
| `active`  | Banner is live. The published config is served to visitors. Scheduled scans run on cadence. Consent events flow in. This is the normal state.                                                                                                                                                                                            |
| `paused`  | Banner stops serving — within seconds, cmp.js stops fetching a config and the banner disappears for new visitors. Existing consent cookies stay valid (visitors who already consented aren't disturbed); new visitors and re-prompts see no banner until you un-pause. All your configs, scan history, and consent events are preserved. |

To pause, edit the domain and set status to **Paused**. Flipping
back to **Active** restores the banner without re-publishing — the
last published config picks up where it left off.

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

- **Bulk CSV** — hit the **Export CSV** button on the consent log
  filter bar. Streamed straight to download so even large logs work
  fine. Unsigned — fine for internal archives and BI, not for legal
  proof.
- **Signed receipts** — open the consent log, filter by the domain,
  download per-event or per-visitor bundles in JSON or PDF. These
  carry the signature regulators ask for.

Recent events (within the last few hours) may take up to overnight to
appear in the bulk CSV — the platform archives consent events from the
edge into the long-term store nightly. If you need an immediate
snapshot including the very latest events, ask support to trigger an
ad-hoc archive before you export.

## Scan cadence

Per-domain — see [Triggering scans](./triggering-scans) for the
mechanics. Settable on the domain detail page from the **Scan cadence**
dropdown (`Weekly` / `Monthly` / `Never`).

## The public key

The `publicKey` (`pk_…`) is a stable identifier for the domain. It's:

- Generated server-side when you create the domain.
- Used by cmp.js' `data-domain-id` attribute on the embed script tag.
- Used by the edge to look up the published config for the domain.
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
circumstances — the platform enforces tenant scoping server-side on
every request, regardless of which user is asking.

Cross-organisation operations (moving a domain from one org to
another) aren't supported in admin. Talk to your account manager if
you genuinely need this.
