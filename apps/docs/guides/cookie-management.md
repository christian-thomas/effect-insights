# Cookie database management

The canonical cookie database lives in Effect Insight and powers the
**Canonical** tab on `/cookies`. It contains vendor cookies (Google, Facebook,
Hotjar, etc.) and their categorisation.

This page covers importing new cookies, triaging unknown ones, and creating
organisation-specific overrides.

## The canonical database

The **Canonical** tab shows the global database — read-only unless you're
importing. It includes:

- Vendor cookies curated by Effect Digital.
- Open Cookie Database entries (CC0 licensed).
- Community contributions via the import workflow.

Filter by pattern, vendor, or category. Pagination defaults to 25 per page
(max 100).

## Importing cookies

Superusers can bulk-import cookie lists (vendor updates, CCPA questionnaires,
third-party audits) from CSV.

1. Go to **Cookies → Imports tab**.
2. Click the navbar import button (or **Import cookies** in the Imports tab).
3. Upload a CSV and optionally tag it with a source version
   (e.g. `ocd-2025-01-15`, `vendor-audit-q1-2025`).
4. The system processes the file asynchronously:
   - New patterns are staged as pending rows.
   - Existing patterns are skipped (idempotent).
   - Duplicates within the same import are deduplicated.
5. Staged rows appear in the **Imports** tab.

## Reviewing staged imports

The **Imports** tab shows pending rows waiting for promotion to canonical.
Each row came from a CSV upload and needs manual triage.

For each row:

- **Promote** → moves it into the canonical database (live immediately).
- **Reject** → marks it as unwanted (removes it from the queue).
- **Promote all** (bulk action) → moves every pending row at once.

Once promoted, the cookie appears in the **Canonical** tab and matches future
scan findings.

## Triaging unknown cookies

When the scanner finds a cookie that doesn't match any canonical pattern or
override, it lands in the **Review queue** tab. Slack also alerts you — see
[Notifications → Unknown cookies](./notifications#unknown-cookies-found).

For each unknown cookie:

1. Click **Create override** (or manually add via **Overrides tab → New override**).
2. Choose the category: **Necessary**, **Preferences**, **Statistics**, or
   **Marketing**.
3. Optionally scope the override to one domain (org-wide by default).
4. Add a purpose + justification (optional but recommended for audit trails).
5. Save.

The override applies to all future scans; the cookie disappears from the review
queue. Existing scans don't re-categorise (they use their own snapshots).

:::tip Defending "unnecessary" cookies
If a cookie looks non-functional (session tracking, internal telemetry), mark
it as **Necessary** with a purpose like _"Session cookie — first-party, no
consent required"_ rather than deleting the row. A simple declaration is
defensible in an audit.
:::

## Organisation-scoped overrides

Overrides can be organisation-wide (apply to every domain you own) or
domain-specific. Use domain-specific when:

- The client has a custom cookie you need to document.
- You're overriding a vendor cookie's default categorisation for one site only.
- You want to suppress a false-positive finding on a particular domain.

See the override management section below for the REST surface.

## Review queue dynamics

The review queue aggregates all **unknown** cookies from all your domain scans
in real time. It's cleared by:

- Creating an override matching the cookie name.
- Promoting an import that covers the pattern.
- Manually categorising via the UI.

Emptying the queue doesn't delete scans — it just means every cookie on every
domain now has an answer (canonical, override, or ignore).

## REST surface

Cookie database endpoints are superuser-only (promotions) or organisation-scoped
(overrides).

```
GET    /api/cookies                          Canonical database, paginated
GET    /api/cookie-imports                   Pending imports (superuser)
POST   /api/cookie-imports                   Upload CSV (superuser)
POST   /api/cookie-imports/promote-all       Bulk-promote pending (superuser)
POST   /api/cookie-imports/{import}/promote  Promote one (superuser)
POST   /api/cookie-imports/{import}/reject   Reject one (superuser)

GET    /api/cookie-overrides                 Your org's overrides
POST   /api/cookie-overrides                 Create new override
PATCH  /api/cookie-overrides/{override}      Update override
DELETE /api/cookie-overrides/{override}      Delete override

GET    /api/scan-findings/review             Unknown cookies (review queue)
```

### Uploading a CSV

```bash
curl -X POST https://api.effectinsight.com/api/cookie-imports \
  -H "Cookie: <superuser sanctum session>" \
  -F "file=@cookies.csv" \
  -F "sourceVersion=ocd-2025-01-15"
```

The CSV must have headers: `namePattern,vendor,category,expiryHint`.

```
namePattern,vendor,category,expiryHint
_ga,Google Analytics,statistics,2 years
_gid,Google Analytics,statistics,1 day
_fbp,Facebook,marketing,90 days
```

Response includes the count created / existing / skipped.

### Promoting an import

```bash
curl -X POST https://api.effectinsight.com/api/cookie-imports/<import-id>/promote \
  -H "Cookie: <superuser sanctum session>"
```

Moves the row into the canonical database. Future scans match against it.

### Creating an override

```bash
curl -X POST https://api.effectinsight.com/api/cookie-overrides \
  -H "Content-Type: application/json" \
  -H "Cookie: <sanctum-session>" \
  -d '{
    "namePattern": "_custom_session",
    "category": "necessary",
    "domainId": null,
    "purpose": "Server-side session identifier",
    "justification": "First-party, essential for user authentication"
  }'
```

All fields except `namePattern` and `category` are optional. `domainId: null`
means organisation-wide; provide a UUID to scope to one domain.

### Listing the review queue

```bash
curl -X GET https://api.effectinsight.com/api/scan-findings/review \
  -H "Cookie: <sanctum-session>"
```

Returns the unknown cookies grouped by name, with scan count per domain.
