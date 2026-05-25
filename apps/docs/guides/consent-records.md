# Consent records and receipts

Every accept / reject / customise click on a visitor's banner
produces a consent event. Events are kept in two stores — a hot
recent-events store for fast reads, and a long-term archive for
audit — and you can download a regulator-ready signed receipt for
any of them.

## Where to find them

Open **Consent log** in the admin (`/consent`). The page lists every
consent event for the current organisation, newest first, with
filters across the top:

- Domain
- Event type (granted / denied / changed / withdrawn)
- Anonymous visitor id
- Date range

Click any row to open the detail slideover.

<!-- TODO: screenshot of the consent log with filters and the row slideover open -->

## What's recorded per event

| Field                     | What it is                                                                                                               |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| **Event id**              | Unique identifier for the event. Stable across retries.                                                                  |
| **Anonymous visitor id**  | Identifies the visitor session. Persists in the consent cookie across visits until the cookie expires.                   |
| **Banner version**        | The published banner config the visitor saw at the moment of consent. Lets you reconstruct what they actually agreed to. |
| **Categories**            | Which of necessary / preferences / statistics / marketing they granted.                                                  |
| **GCM signals**           | The seven Google Consent Mode v2 signals and their granted/denied state.                                                 |
| **Event type**            | granted, denied, changed, or withdrawn.                                                                                  |
| **Occurred at**           | Timestamp on the visitor's clock.                                                                                        |
| **Country**               | ISO 3166-1 alpha-2 from Cloudflare edge, where available.                                                                |
| **Hashed UA + hashed IP** | Privacy by design — the raw values are never stored.                                                                     |
| **Domain + organisation** | Which of your sites the event came from.                                                                                 |

## Receipt downloads

The consent log slideover has a **Download receipt** button with two
format options.

### Signed JSON (recommended for compliance)

A self-contained JSON file with:

- Every field of the event.
- A snapshot of the banner config the visitor actually saw — so you
  can prove what they agreed to, not just that they agreed.
- Generation metadata (when the receipt was downloaded, by whom).
- An HMAC signature over the canonical content.

Anyone with the platform's signing key can re-compute the signature
to confirm the receipt hasn't been tampered with after export. This
is the artefact regulators ask for when they want proof.

### PDF (human-readable)

The same data laid out as a one-page document with the signature
printed at the foot for offline verification. Use this when a
regulator asks for "the receipt" and you don't want to explain HMAC.

## Per-visitor bundles

If a visitor's anonymous id has multiple events (they accepted, then
later changed their preferences, then later withdrew), download them
as a single bundle:

1. Filter the consent log by anonymous id + domain.
2. Click **Download visitor receipt** in the filter bar.
3. Pick JSON or PDF.

The bundle contains every event in chronological order with the
banner config snapshots they reference, under a single signature
that covers the whole bundle.

## Bulk CSV export

For BI dashboards, spreadsheet pivots, and e-discovery exports, the
filter bar has an **Export CSV** button. It produces a streamed CSV
matching whatever filters are active on the consent log page.

Each row contains the standard event fields. Cookie categories and
GCM signals JSON-encode into a single cell each so the grid stays
flat.

::: warning Use signed receipts for legal proof, not the CSV
The CSV is unsigned — fine for analytics, BI, and internal audit.
For regulator submissions ("prove this visitor consented"), download
a signed per-event or per-visitor receipt instead.
:::

## Data retention

By default events are kept indefinitely. To set an auto-purge
window for your organisation, open the **Organisations** slideover
(superuser-only) and fill in **Consent retention days**:

- Blank — keep forever (the default).
- A number between 1 and 3650 — anything older than that is
  hard-deleted at the next daily purge.

::: warning Hard delete
The purge bypasses receipts. Deleted events cannot be re-downloaded.
Keep the window generous enough to satisfy your regulator's bar —
the UK ICO guidance is "indefinite while consent is in force",
typically at least the banner's re-prompt window.
:::

## What's NOT in a receipt

- The raw IP address (only the hash).
- The raw User-Agent string (only the hash).
- The visitor's identity beyond the anonymous id — Effect Insight
  doesn't know who the visitor is.
- Links to other systems (CRM, support tickets, etc.) — out of scope.

If a regulator asks for "the personal data we hold on this visitor",
the consent record itself contains no directly-identifying personal
data; the records exist _as proof that consent was correctly
recorded_ for a session-scoped anonymous identifier. Your CRM and
analytics exports are separate questions.

## Related

- [Managing domains](./managing-domains) — exporting consent before
  you delete a domain.
- [Triggering scans](./triggering-scans) — the cookie scanner that
  populates the inventory the banner is gating.
- [Troubleshooting → Receipt download fails](./troubleshooting/consent#receipt-download-fails)
  — what to do when JSON/PDF download errors.
