# Cookie database management

The canonical cookie database powers the **Canonical** tab on
`/cookies`. It contains vendor cookies (Google, Facebook, Hotjar,
etc.) and how they're categorised.

This page covers importing new cookies, triaging unknown ones, and
creating organisation-specific overrides.

## The canonical database

The **Canonical** tab shows the platform-wide database — read-only
unless you're a superuser running an import. It includes:

- Vendor cookies curated by Effect Digital.
- Open Cookie Database entries (CC0 licensed).
- Community contributions promoted via the import workflow.

Filter by pattern, vendor, or category. Pagination defaults to 25
per page (max 100).

<!-- TODO: screenshot of the Canonical tab with filters in use -->

## Importing cookies

Superusers can bulk-import cookie lists (vendor updates, CCPA
questionnaires, third-party audits) from CSV.

1. Open `/cookies` and switch to the **Imports** tab.
2. Click **Import cookies**.
3. Upload a CSV and optionally tag it with a source version
   (e.g. `ocd-2025-01-15`, `vendor-audit-q1-2025`).
4. The system processes the file in the background:
   - New patterns are staged as pending rows.
   - Existing patterns are skipped (the import is idempotent).
   - Duplicates within the same file are deduplicated.
5. Staged rows appear in the **Imports** tab waiting for triage.

### CSV format

Four columns, with these exact headers:

```
namePattern,vendor,category,expiryHint
_ga,Google Analytics,statistics,2 years
_gid,Google Analytics,statistics,1 day
_fbp,Facebook,marketing,90 days
```

`category` must be one of `necessary`, `preferences`, `statistics`,
`marketing`.

## Reviewing staged imports

The **Imports** tab shows pending rows waiting for promotion to the
canonical database. Each row came from a CSV upload and needs
manual triage.

For each row:

- **Promote** — moves it into the canonical database (live
  immediately).
- **Reject** — marks it as unwanted (removes it from the queue).
- **Promote all** (bulk action) — moves every pending row at once.

Once promoted, the cookie appears in the **Canonical** tab and
matches future scan findings.

## Triaging unknown cookies

When the scanner finds a cookie that doesn't match any canonical
pattern or override, it lands in the **Review queue** tab on
`/cookies`. Slack also alerts you — see
[Notifications → Unknown cookies](./notifications#unknown-cookies-found).

For each unknown cookie:

1. Click **Create override** (or manually add via the **Overrides**
   tab → **New override**).
2. Choose the category: **Necessary**, **Preferences**,
   **Statistics**, or **Marketing**.
3. Optionally scope the override to one domain (org-wide by default).
4. Add a purpose and justification (optional, recommended for audit
   trails).
5. Save.

The override applies to all future scans; the cookie disappears from
the review queue. Existing scans don't re-categorise — they're
snapshots of what the resolver saw at the time.

:::tip Defending "unnecessary" cookies
If a cookie looks non-functional (session tracking, internal
telemetry), mark it as **Necessary** with a purpose like _"Session
cookie — first-party, no consent required"_ rather than deleting the
row. A simple declaration is defensible in an audit.
:::

## Organisation-scoped overrides

Overrides can be **organisation-wide** (apply to every domain you
own) or **domain-specific**. Use domain-specific when:

- The client has a custom cookie you need to document.
- You're overriding a vendor cookie's default categorisation for one
  site only.
- You want to suppress a false-positive finding on a particular
  domain.

The classifier walks domain override → org override → canonical
database, so a domain-scoped override always wins over an org-wide
one for that domain.

## Review queue dynamics

The review queue aggregates all unknown cookies across all your
domain scans in real time. It clears when you:

- Create an override matching the cookie name.
- Promote an import that covers the pattern.
- Categorise it manually via the UI.

Emptying the queue doesn't delete the scans — it just means every
observed cookie on every domain now has a categorisation answer.

## Related

- [Cookie policy generation](./cookie-policy-generation) — how the
  canonical database and overrides feed the public cookie policy
  iframe.
- [Triggering scans](./triggering-scans) — what the scanner observes
  and how findings end up in the review queue.
- [Notifications](./notifications) — Slack alerts on unknown cookies.
