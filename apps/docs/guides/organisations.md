# Organisations

Effect Insight is multi-tenant. Every domain, banner config, consent
event, scan, and accessibility scan belongs to an **organisation** —
the tenant boundary. Operators belong to one or more organisations and
switch between them via the org-switcher in the admin sidebar.

This page is for the superuser at Effect Digital who provisions and
configures tenants. Regular operators don't see most of this surface.

## What an organisation is

One organisation maps to one client. Everything that organisation's
operators see — domains, banner configs, consent log, scans, cookie
overrides, accessibility findings — is scoped to that tenant. You will
never see another organisation's data, and they won't see yours.

## Where the org-switcher lives

The current organisation name sits at the top of the admin sidebar.
Click it to open a menu of every organisation you belong to; clicking
one switches the active tenant. The whole admin re-fetches its data
under the new scope — domains, scans, consent events, everything.

## The organisation slideover

Open **Organisations** in the admin sidebar (superuser-only) and click
the edit pencil on a row, or **New organisation** to create one. The
slideover exposes:

- **Name** — display name (e.g. "Acme Corp"). Required.
- **URL-safe identifier** — a slug used in admin URLs and audit logs.
  Must be unique across all tenants. Required.
- **Consent retention** — number of days to keep consent events before
  auto-purge, or blank for indefinite. See
  [Consent records → Configurable retention](./consent-records#configurable-retention-per-organisation).
- **Notification email** — comma-separated list of addresses that get
  alerts alongside Slack. Blank = Slack-only. See
  [Notifications → Email recipients](./notifications#email-recipients).
- **Accessibility alert threshold** — per-tenant override of the
  platform-wide impact threshold (Critical / Serious / Moderate /
  Minor). Blank inherits the platform default. See
  [Accessibility scans → Alert threshold](./accessibility-scans#alert-threshold).
- **Accessibility alert scope** — `All rules` or `WCAG only` (best-
  practice findings drop out of the alert count). See
  [Accessibility scans → Alert scope](./accessibility-scans#alert-scope-wcag-vs-best-practice).

Each field has inline help explaining the trade-offs (retention, blank-
inherits-platform-default on thresholds, etc.).

## Inviting users to a new organisation

Once the organisation is saved, switch to the **Users & invitations**
tab on the same slideover and send a magic-link invitation. See
[User management](./user-management) for the full flow.

## Deleting an organisation

::: warning This is destructive
Deleting an organisation cascades through:

- Every domain in the tenant
- Every banner config (draft + published + history)
- Every cookie scan + finding
- Every accessibility scan + finding
- **All consent events** for that tenant
- Every cookie override + accessibility rule override
- Every user-organisation membership

There's no soft-delete. Export the consent log first if you need to
retain it for audit — see
[Consent records → Bulk export](./consent-records#bulk-csv-export).
:::

If you only need to stop a tenant from running (not erase it), pause
each of its domains instead — see
[Managing domains → Domain status](./managing-domains#domain-status).

## Cross-organisation operations

Moving a domain from one organisation to another isn't supported in
admin. If you genuinely need this, talk to support — it's a manual
operation that has to preserve consent-log lineage cleanly.
