# Accessibility troubleshooting

Symptoms specific to scheduled axe-core scans, findings, alerting, and
the trend chart. The main guide is
[Accessibility scans](../accessibility-scans) — this page covers what to
do when something on that pipeline goes wrong.

## Accessibility scan failed

A scan ends up in `failed` state on the per-domain Accessibility tab,
or the `/accessibility-scans` list shows a failed row.

| Symptom                                       | Cause                                                                                       | Fix                                                                                                                                                            |
| --------------------------------------------- | ------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Error mentions `timeout` on a single page     | A page took longer than the 30s per-page timeout — usually a heavy SPA, slow CDN, or modal that holds `networkidle0` open | Retry the scan. If a specific URL is the culprit, exclude it from the sitemap or fix the page's load behaviour. |
| Error mentions `timeout` for the whole job    | The job hit the 600s scan timeout — typically a 150-URL crawl where every page is slow      | Reduce the sitemap or split the domain into multiple registered hostnames. Retry.                                                                              |
| Error mentions `blocked` or `403`             | Bot protection on the target site (Cloudflare WAF, Akamai, custom rules) is rejecting our browser | Whitelist Cloudflare's Browser Rendering IPs on the target site. Cloudflare publishes the range.                                                              |
| Error mentions `quota`                        | Cloudflare Browser Rendering plan limit hit                                                 | Upgrade the Cloudflare account or reduce a11y + cookie scan cadence across the fleet.                                                                          |
| All a11y scans failing at once across the fleet | Cloudflare Browser Rendering outage or scanner worker deploy regression                     | Check https://www.cloudflarestatus.com — see the [outage runbook](https://github.com/effect-digital/cmp-project/blob/main/docs/runbooks/cloudflare-outage.md). |

## Scan stays in `queued`

You triggered a scan (manually or by cadence) and the row sits at
`queued` indefinitely.

| Symptom                                              | Cause                                                                | Fix                                                                                                                |
| ---------------------------------------------------- | -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Only a11y scans are queued, cookie scans run fine   | The background-job worker for accessibility scans isn't running     | Check the operations dashboard. If the worker is down, restart it; the queued scan picks up on the next poll.      |
| All scans (a11y + cookie) are queued                 | The whole queue infrastructure is down                               | See the [Cloudflare outage runbook](https://github.com/effect-digital/cmp-project/blob/main/docs/runbooks/cloudflare-outage.md). |
| Scan was queued days ago and is still pending        | A previous failure left the queue stuck — the new scan is waiting    | Trigger a manual rescan. If it still queues, escalate to [support@effectdigital.com](mailto:support@effectdigital.com). |

## Scan stays in `running` past 10 minutes

The scan started but never finishes — usually the headless browser is
hanging on one page.

| Symptom                                                         | Cause                                                                                  | Fix                                                                                                                       |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| One specific URL is consistently the last one logged before stall | A page on the target site never resolves `networkidle0` (long-poll connection, video stream, infinite analytics beacon) | Identify the page, then exclude it from the sitemap or fix the underlying load behaviour. Retry the scan.                |
| Stall is random across pages                                    | Browser-rendering hang on Cloudflare's side                                            | Wait for the 600s job timeout to fire — the scan transitions to `failed` with a timeout error. Retry, escalate if persistent. |

## Scan completes with `urls_scanned = 0`

The scan returns successfully but no pages were actually scanned.

| Symptom                                                        | Cause                                                                                                        | Fix                                                                                                                                                            |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `urls_scanned = 0`, sitemap discovery fell back to apex-only   | `/sitemap.xml` is missing, `/robots.txt` has no `Sitemap:` directive, AND the apex page failed to load       | Confirm the apex URL loads in a regular browser. If it does, raise a sitemap with the client — without one we can only crawl what we can discover.            |
| `urls_scanned = 0`, apex returns 5xx or redirect loop          | The target site is down or misconfigured at the apex                                                         | Test the URL manually. Fix the target site, or update the registered domain to point at a hostname that resolves.                                              |
| `urls_scanned = 0`, sitemap exists but is empty                | The sitemap parser found a valid XML file with no `<url>` entries                                            | Inspect the sitemap. The client's CMS may be emitting a stub — get a real one published.                                                                       |

## No findings on a page you know is broken

You're looking at a finished scan and a page with known WCAG issues
(missing alt text, low-contrast headings, etc.) shows no violations.

| Symptom                                                                 | Cause                                                                                                                                                          | Fix                                                                                                                                                                                                     |
| ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Findings exist but they're all dimmed / tagged "Muted"                  | Per-domain or org-wide rule mutes are active                                                                                                                   | Open `/accessibility-rule-overrides` in admin. Remove any rules that shouldn't be muted, or expand the finding card and confirm the mute scope. Muting affects reporting only — the next scan re-checks. |
| Page loads but axe-core finds nothing on it                             | The page is a thin client-side shell — by the time we hit `networkidle0`, the real content hasn't rendered yet                                                | Confirm the URL renders meaningful HTML server-side. Pure CSR apps need an SSR/SSG layer for our scanner (and for Google) to see them.                                                                  |
| Page loaded but axe-core wasn't injected (no findings AND no errors)    | Very rare — usually a Content-Security-Policy on the target site rejected our script injection                                                                | Check the target site's CSP. Our scanner needs to be able to inject inline scripts; if the site forbids that, the scan will return clean even on broken pages.                                          |
| The page genuinely passes                                               | axe-core's automated checks cover roughly 30-40% of WCAG criteria — colour-contrast, alt text, ARIA roles, semantic landmarks. Some issues need manual review. | Use a human-driven audit (NVDA / VoiceOver / keyboard-only) for the issues axe doesn't catch. Document the manual findings against the domain separately.                                               |

The axe-core version that runs is fixed per worker deploy — not pulled
from a CDN. If you suspect a rule we should be catching is missing,
raise it with [support@effectdigital.com](mailto:support@effectdigital.com) and we'll check the bundled
version.

## Slack alert didn't fire despite findings

A scan completed with Critical / Serious findings, the threshold says
those should alert, but no Slack message arrived.

| Symptom                                                                | Cause                                                                                                                                                                   | Fix                                                                                                                                                                                                                       |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Findings exist but they're all best-practice rules                    | `a11y_alert_scope` is set to `wcag_only` for your org — best-practice findings drop out of the threshold count                                                          | Either accept that best-practice rules stay silent, or flip the scope to `all` in the organisation slideover. See [Accessibility → Alert scope](../accessibility-scans#alert-scope-wcag-vs-best-practice).               |
| Threshold is `critical` and findings are `serious` or below           | The threshold is exclusive of lower levels — `critical` means "only Critical fires"                                                                                     | Lower the threshold to `serious` (the platform default) in the organisation slideover. See [Accessibility → Alert threshold](../accessibility-scans#alert-threshold).                                                    |
| Above-threshold findings exist but they're all on muted rules         | Muted rules drop out of the alert pipeline — a scan that finds only muted rules above the threshold won't fire                                                          | Review active mutes at `/accessibility-rule-overrides`. Remove any that shouldn't be in effect.                                                                                                                            |
| Slack channel was never configured for the org                         | Notifications aren't set up                                                                                                                                             | Open **Settings → Notifications** in admin and add a Slack webhook. See [Notifications](../notifications).                                                                                                                |
| All Slack alerts across all orgs are missing                           | Slack-side outage or our webhook integration is degraded                                                                                                                | Check Slack status. Check the operations dashboard for failed notification jobs.                                                                                                                                          |

## Scheduler skipped a domain

You expected the 05:00 UTC scheduler to dispatch a scan for a domain
and it didn't.

| Symptom                                                                  | Cause                                                                                                                                                          | Fix                                                                                                                                                                                                  |
| ------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Domain's cadence is `Never`                                              | The default is opt-in — `Never` means the scheduler skips it                                                                                                  | Set the cadence to `Weekly` or `Monthly` on the domain detail page. See [Accessibility → Cadence](../accessibility-scans#cadence).                                                                  |
| Cadence is `Weekly` / `Monthly` but no scan ran today                    | The stagger window spreads domains deterministically across the cadence interval — a weekly domain only runs on its assigned day of the 7-day window         | Wait. To force one out of cycle, hit **Run scan now** on the per-domain Accessibility tab.                                                                                                          |
| Last scan ran more recently than the cadence interval                    | The scheduler only dispatches when the cadence interval has elapsed since the last scan                                                                       | Wait, or trigger manually.                                                                                                                                                                          |
| Scheduler ran but no domains were dispatched at all                      | The 05:00 UTC scheduled command didn't fire, or all domains are on `Never`                                                                                    | Check the scheduler log. If the cron didn't run, escalate to [support@effectdigital.com](mailto:support@effectdigital.com).                                                                          |

## Trend chart empty

The Accessibility trend card on a domain detail page shows no bars.

| Symptom                                                       | Cause                                                                                  | Fix                                                                                                                |
| ------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Domain has never had a completed accessibility scan          | Trend draws from `completed` scans only — `failed` and `queued` don't contribute      | Run the first scan via **Run scan now** on the per-domain Accessibility tab.                                       |
| Every scan in the selected window was `failed`               | All scans within the chosen 7 / 30 / 90 / 365-day window errored out                  | Investigate the failure mode — see [Accessibility scan failed](#accessibility-scan-failed) above. Once a scan completes, the chart populates from that point forward. |
| Chart is empty for 7 days but shows data on 30 / 90 days     | This is normal for a `Monthly` cadence — there may not have been a scan inside 7 days  | Switch the window selector to 30 or 90 days, or run a manual scan.                                                |

Note that rule muting doesn't retroactively rewrite trend history. The
chart reflects what axe actually found at each scan, which is the right
reference point for "is this getting better or worse" even after you've
muted noisy rules.

## Related

- [Accessibility scans](../accessibility-scans) — the main guide.
- [Notifications](../notifications) — Slack channel setup.
- [Consent troubleshooting](./consent) — cookie scan failure modes (the
  pipeline shares a fair bit of infrastructure but the symptoms are
  distinct).
