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
| `pending` | Domain exists but no banner is live. The banner editor can be used (draft + publish), but cmp.js calling `/config/{publicKey}` returns 404 — embed sites won't see a banner. Scans can be triggered manually but are most usefully run after publish. Use this state while you're configuring before the embed snippet goes on the client site.                             |
| `active`  | Banner is live. cmp.js fetches the published config. Scheduled scans run on cadence. Consent events flow in. This is the normal state.                                                                                                                                                                                                                                      |
| `paused`  | Banner stops serving — cmp.js' config fetch returns the published config marked `paused` and the loader **does not render**. Scheduled scans skip the domain. Existing consent cookies stay valid (visitors who already consented aren't disturbed); only new visitors and re-prompts are affected. Use this to temporarily disable the banner without deleting the config. |

Currently the admin doesn't expose an explicit "publish" → status change
— publishing the first banner config flips the domain to `active`
implicitly. To pause, edit the domain and set status manually.

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
log first via **Consent log → Download CSV** (TODO: this export
doesn't exist yet — for now, run the per-event signed receipt
download from the slideover, or use the `php artisan
consent-events:archive` flow to push events to the long-term MySQL
archive before deletion). Then proceed with the delete.

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
