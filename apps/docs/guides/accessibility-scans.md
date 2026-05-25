# Accessibility scans

Effect Insight runs scheduled axe-core accessibility audits against
client websites alongside the cookie scanner. Same domain registry,
separate cadence, separate findings, separate alerts. Opt-in per
domain so clients who don't want a11y coverage aren't billed for it.

## Quick start

1. Open the domain detail page in admin (`/domains/{id}`).
2. Under **Scheduled scans**, set **Accessibility scan cadence** to
   `Weekly` or `Monthly`. The default is `Never` (opt-in).
3. Either wait for the next scheduled sweep, or click
   **Run scan now** on the per-domain Accessibility tab.
4. The scan runs in the background. Refresh after a few minutes —
   completed scans show violations grouped by impact
   (Critical / Serious / Moderate / Minor).

<!-- TODO: screenshot of the per-domain Accessibility tab with completed scan + impact breakdown -->

## How it works

For each enabled domain the platform:

1. **Discovers URLs.** Same approach as the cookie scanner — pull
   `/sitemap.xml`, fall back to `/robots.txt` `Sitemap:` directives,
   fall back to the apex page only. Capped at 150 URLs per scan.
2. **Runs axe in a headless browser.** Each URL is loaded with a real
   browser, axe-core is injected, and the resulting violations are
   captured.
3. **Stores findings.** Each violation lands in the scan's findings
   list with its rule id, impact, description, help text, and the
   specific HTML node that failed.
4. **Alerts on threshold breach.** Findings at or above your
   configured impact threshold trigger a Slack/email alert. The same
   alerting channel as unknown-cookie and scan-failure alerts.

## Cadence

Per-domain. Three options, same shape as the cookie scanner:

| Cadence   | Interval | Stagger window |
| --------- | -------- | -------------- |
| `weekly`  | 7 days   | 7 days         |
| `monthly` | 30 days  | 28 days        |
| `never`   | —        | —              |

The scheduler walks every domain whose cadence isn't `never` and
dispatches a scan when the interval has elapsed since the last one.
Never-scanned domains land on a deterministic day inside the cadence
window so a portfolio of weekly domains spreads across the seven
days rather than all hammering Monday morning.

## Reading a finding

Each finding shows:

- **Rule id** (e.g. `color-contrast`) — the axe-core rule that fired.
- **Impact** — one of Critical, Serious, Moderate, Minor. This is
  axe-core's own taxonomy.
- **Description + help text** — what's wrong, with a link to the
  Deque University reference page for full remediation guidance.
- **Tags** — WCAG levels (`wcag2aa`, `wcag143`, etc.) and/or
  `best-practice`. See [WCAG vs best-practice](#wcag-vs-best-practice-findings)
  below for why this matters.
- **The offending HTML** — the specific node that failed and a CSS
  selector pointing to it.

Findings are ordered by severity (Critical first) so you can triage
without re-sorting.

## Alert threshold

The alert fires when any violation at or above your configured impact
threshold exists in a completed scan. The platform default is
**Serious**, which means Critical or Serious findings trigger an
alert and Moderate or Minor stay quiet.

To change the threshold for your organisation, open the
**Organisations** page (superuser-only), click the edit pencil on
your org, and pick a new value in the **Accessibility alert
threshold** field:

- **Critical** — only Critical findings alert.
- **Serious** — Critical or Serious findings alert (default).
- **Moderate** — Moderate and worse alert.
- **Minor** — every violation alerts.

Setting the threshold below Serious is noisy — most axe runs find at
least a handful of Minor issues even on well-built sites.

## WCAG vs best-practice findings

axe-core emits two categories of rule:

- **WCAG rules** (tagged `wcag2a`, `wcag2aa`, `wcag21aa`, etc.) are
  the legally-required conformance checks — what a regulator or
  accessibility auditor would flag.
- **Best-practice rules** (tagged `best-practice`) are advisory —
  useful, but not strictly required for WCAG conformance.

The scan persists both. The scan detail page has a filter strip
(All / WCAG / Best practice) so you can focus on legally-required
findings at a glance.

## Alert scope (WCAG vs best-practice)

Companion to the alert threshold. The threshold controls _severity_;
the scope controls _which kinds of rules_ count.

Two values, set per-org from the **Organisations** slideover:

- **All** (default) — every finding above the impact threshold
  counts.
- **WCAG only** — best-practice findings drop out of the threshold
  count. A scan that finds only best-practice issues stays silent
  regardless of impact level.

Rules tagged with both `wcag*` and `best-practice` (rare but real)
count as WCAG, so legally-required findings are never accidentally
suppressed.

The scope filter applies only to _alert dispatching_. The scan
detail page still shows every finding, and the trend chart still
includes everything axe found — only the Slack/email notification
decision is affected.

## Rule muting

Some rules will never apply to your sites — accessibility is
case-by-case, and a "violation" that legal has explicitly signed off
as acceptable shouldn't keep paging on-call. Two scopes of mute:

- **Org-wide** — applies across every domain in the organisation.
- **Per-domain** — applies only to one domain's scans.

Muting has two effects:

1. Findings for that rule no longer count toward the alert
   threshold. A scan that finds only muted rules above the threshold
   won't fire an alert.
2. The scan detail page tags those findings with a "Muted" badge
   and dims them so they're visually de-emphasised.

The audit trail stays intact — muted findings are still recorded and
the per-scan counters still reflect what axe actually found. Mute is
a reporting layer, not a deletion.

### Adding a mute

From the scan detail page, expand any finding card and click
**Just this domain** or **Org-wide**. The prompt asks for a reason
(helps the next person reading the audit trail know why).

### Reviewing or removing mutes

Open `/accessibility-rule-overrides` in admin. The page lists every
active override for the current org with rule id, scope, reason, and
a delete button. Removing an override un-mutes immediately — the
next scan and alert run treat the rule as active again.

<!-- TODO: screenshot of the rule-overrides admin page -->

## Historical trend

Each domain detail page in the admin shows an **Accessibility trend**
card with a stacked-bar chart of violations per completed scan in the
selected window (7 / 30 / 90 / 365 days). Stack order from bottom to
top: Critical → Serious → Moderate → Minor. Hover any bar for the
exact counts.

The trend reflects what axe actually found at each scan, so muting a
rule after the fact doesn't retroactively rewrite history. That's
the right reference point for "is this getting better or worse" even
after you've muted noisy rules.

Failed scans are excluded; only completed scans contribute data
points.

## Related

- [Notifications](./notifications) — Slack channel and email recipient setup.
- [Triggering scans](./triggering-scans) — cookie scanner's
  equivalent doc (most of the operational shape is the same).
- [Troubleshooting → Accessibility](./troubleshooting/accessibility) — common scan failure modes.
