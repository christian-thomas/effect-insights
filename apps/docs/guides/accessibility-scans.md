# Accessibility scans

Effect runs scheduled [axe-core](https://github.com/dequelabs/axe-core)
accessibility audits against client websites — same Domain registry as
the cookie scanner, separate cadence, separate findings, separate
Slack alerts. Designed to sit alongside the CMP without forcing every
client to opt in.

## Quick start

1. Open a domain detail page in admin.
2. Under **Scheduled scans** set **Accessibility scan cadence** to
   `Weekly` or `Monthly`. Default is `Never` (opt-in).
3. Either wait for the next 05:00 UTC scheduler run, or click
   **Run scan now** on the per-domain Accessibility tab.
4. The scan runs in the background (Horizon queue:
   `accessibility-scans`). Refresh the page after a few minutes to
   see the result — completed scans show violations grouped by
   impact (Critical / Serious / Moderate / Minor).

## How it works

The pipeline shares infrastructure with the cookie scanner but
maintains its own data shape:

1. **URL discovery.** Same `SitemapDiscoveryService` as the cookie
   scanner — fetches `/sitemap.xml`, falls back to `/robots.txt`
   `Sitemap:` directives, falls back to apex-only. Same 150-URL
   cap per scan.
2. **Browser run.** `apps/edge/src/scanner-worker.ts` exposes a
   `/scan-a11y` route. For each URL it:
   - Navigates via `@cloudflare/puppeteer` (`waitUntil:
     networkidle0`, 30s timeout).
   - Injects `axe-core` from jsDelivr via `page.addScriptTag`.
   - Runs `axe.run()` in the page context.
   - Collects `violations[]` and returns them.
3. **Persistence.** `App\Services\Accessibility\AccessibilityScanRunner`
   stores one row per violation in `accessibility_findings`, with
   aggregate counters denormalised onto the parent
   `accessibility_scans` row (`violations_total`,
   `violations_by_impact`).
4. **Alerting.** When a scan completes, `AccessibilityAlertService`
   checks the impact threshold (default `serious`) and fires a Slack
   notification if any violation at or above that level exists. Same
   channel as the unknown-cookie + scan-failure alerts.

## Cadence

Per-domain. Three options, same as the cookie scanner's cadence:

| Cadence   | Interval | Stagger window |
| --------- | -------- | -------------- |
| `weekly`  | 7 days   | 7 days         |
| `monthly` | 30 days  | 28 days        |
| `never`   | —        | —              |

The 05:00 UTC `accessibility:dispatch-scheduled` command walks every
domain whose cadence isn't `never` and dispatches a queued scan when
the interval has elapsed since the last one. Never-scanned domains
land on a deterministic day inside the cadence window (CRC32 of the
domain UUID mod `staggerWindow`) so a fleet of weekly domains
spreads across 7 days rather than all hammering Monday morning.

## API

All endpoints sit behind Sanctum SPA auth + the `org.current`
middleware, same as the cookie scanner. Tenant-scoped.

```
GET    /api/accessibility-scans                          List, paginated
GET    /api/accessibility-scans/{accessibilityScan}      Detail + findings
POST   /api/domains/{domain}/accessibility-scans         Manual trigger (202)
```

The list endpoint accepts `?domain_id=…` to filter to a specific
domain. The detail endpoint returns findings already ordered by
impact severity (Critical first) so the admin renders without
re-sorting.

`PATCH /api/domains/{domain}` accepts `a11y_scan_cadence` so
operators can flip cadence from the same form as the cookie cadence.

## Finding shape

```json
{
  "id": "uuid",
  "accessibilityScanId": "uuid",
  "ruleId": "color-contrast",
  "impact": "serious",
  "description": "Elements must have sufficient color contrast",
  "helpText": "How to fix...",
  "helpUrl": "https://dequeuniversity.com/rules/axe/4.x/color-contrast",
  "url": "https://example.com/about",
  "tags": ["wcag2aa", "wcag143"],
  "nodes": [
    {
      "target": ["#main > p.subhead"],
      "html": "<p class=\"subhead\" style=\"color: #ccc\">…</p>",
      "failureSummary": "Fix any of the following: Element has insufficient color contrast..."
    }
  ]
}
```

`impact` is one of `critical | serious | moderate | minor` —
axe-core's own taxonomy.

## Alert threshold

The alert fires when any violation at or above the configured impact
threshold exists in a completed scan. The platform default is
`serious`, meaning Critical or Serious findings trigger an alert and
Moderate or Minor stay quiet.

Two override layers:

1. **Platform-wide** (env var) — applies to every org that doesn't set
   their own override.
2. **Per-organisation** (`organisations.a11y_alert_threshold` column) —
   overrides the platform default for one tenant. Set via the admin's
   organisation slideover or `PATCH /api/organisations/{id}`.

```bash
# Platform default
ACCESSIBILITY_ALERT_THRESHOLD=critical   # only Critical alerts
ACCESSIBILITY_ALERT_THRESHOLD=serious    # default
ACCESSIBILITY_ALERT_THRESHOLD=moderate   # Moderate and worse
ACCESSIBILITY_ALERT_THRESHOLD=minor      # every violation

# Per-org override (via API)
PATCH /api/organisations/{uuid}
{ "a11y_alert_threshold": "minor" }
```

Per-org override is `null` by default, meaning "inherit the platform
default". Resolution order in
{@see App\Services\Notifications\AccessibilityAlertService}:
per-org column → platform env → `serious`.

Setting a threshold below `serious` is noisy — most axe runs find at
least a handful of `minor` issues even on well-built sites.

## Cost / quota considerations

axe-core takes 2–5s per page versus &lt;2s for the cookie scanner.
A 50-URL crawl is a ~3-minute job, comfortably inside the 600s
Horizon timeout. The 05:00 UTC cron is offset 1h from the cookie
scanner's 04:00 so the two don't fight for Cloudflare Browser
Rendering quota.

If client adoption grows and a11y scans start starving cookie scans,
the next step is a dedicated `tracewatch-accessibility-scanner`
worker on a separate Browser Rendering plan. Until then, the shared
worker is the simpler operationally.

## WCAG vs best-practice findings

axe-core emits two categories of rule. **WCAG rules** (tagged
`wcag2a`, `wcag2aa`, `wcag21aa`, `wcag143` etc.) are the legally-
required conformance checks — what a regulator or accessibility
auditor would flag. **Best-practice rules** (tagged `best-practice`)
are advisory — useful, but not strictly required for WCAG
conformance.

The scan persists both. The scan detail page has a filter strip
(All / WCAG / Best practice) so you can focus on legally-required
findings at a glance. The Slack/email alert pipeline doesn't yet
distinguish — a `serious` best-practice rule trips the same
threshold as a `serious` WCAG rule. Tag-aware alert filtering is on
the roadmap if that gets noisy.

## Rule muting

Some rules will never apply to your sites — accessibility is
case-by-case, and a "violation" that legal has explicitly signed off
as acceptable shouldn't keep paging on-call. The
`accessibility_rule_overrides` table lets you mute a rule:

- **Org-wide** — applies across every domain in the organisation.
- **Per-domain** — applies only to one domain's scans.

Muting has two effects:

1. The Slack/email alert pipeline skips findings for that rule when
   computing the impact-threshold count. A scan that finds only
   muted rules above the threshold won't fire an alert.
2. The admin's scan detail page tags those findings with a "Muted"
   badge and dims them so they're visually de-emphasised.

The audit trail stays intact — muted findings still land in
`accessibility_findings` and the per-scan counters still reflect
what axe actually found. Mute is a reporting layer, not a deletion.

### Adding a mute

From the scan detail page, expand any finding card and click
**Just this domain** or **Org-wide**. The prompt asks for a reason
(helps the next person reading the audit trail know why).

Or via the API:

```bash
curl -X POST https://api.tracewatch.io/api/accessibility-rule-overrides \
  -H "Content-Type: application/json" \
  -H "Cookie: <sanctum-session>" \
  -d '{
    "rule_id": "color-contrast",
    "domain_id": null,
    "reason": "Brand colours signed off by legal 2026-04-01 — ENG-123"
  }'
```

`domain_id: null` makes it org-wide; a domain UUID scopes it.

### Reviewing + removing mutes

`/accessibility-rule-overrides` in admin lists every active override
for the current org with rule id, scope, reason, and a delete button.
Removing an override un-mutes immediately — the next scan + alert run
treats the rule as active again.

## Limitations

- **CDN-loaded axe-core.** The worker pulls `axe.min.js` from
  jsDelivr per scanned page rather than bundling 500KB into the
  worker image. If jsDelivr is unavailable the scan fails (the
  runner records the error, fires the failure alert, moves on).
  Acceptable for v1; we can switch to a Wrangler text-bundle import
  later for offline guarantees.
- **No historical trend dashboard.** The violations_by_impact counts
  are stored per scan but there's no chart of "Critical violations
  on example.com over the last 90 days" yet.
- **No tag-aware alert filtering.** Best-practice + WCAG rules at the
  same impact level trip the same threshold. Filter visually in the
  admin via the WCAG/Best-practice tab on the scan detail page; or
  mute the noisy rules.

## Related

- [Notifications](./notifications) — Slack channel setup.
- [Triggering scans](./triggering-scans) — cookie scanner's
  equivalent doc (most of the operational shape is the same).
- [Troubleshooting](./troubleshooting) — common scan failure modes.
