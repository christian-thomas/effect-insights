# Triggering scans

The scanner crawls your site with a real browser, captures every cookie set
and every third-party request fired, and resolves them against the canonical
cookie database + your overrides. Two ways to trigger one.

## Manual — from the admin

1. Navigate to **Domains → {your domain} → Scans**.
2. Click **Trigger scan**. The button returns immediately with a queued scan;
   the actual crawl runs in the background.
3. Within a minute or two (depending on how many URLs your site advertises),
   the scan transitions to **Completed** and the **Findings** section
   populates.
4. Open the scan to see cookies grouped by category, plus a **Compared to
   previous scan** card highlighting any cookies that are new since last time.

## Scheduled — automatic

Each domain has a **Scan cadence** setting (weekly / monthly / never) on its
detail page. The scheduler runs daily at 04:00 UTC and dispatches a scan for
every domain whose interval has elapsed.

Scans are staggered deterministically so a portfolio of 100 weekly-cadence
domains spreads across 7 days of the week — the scanner worker isn't
hammered Monday morning.

| Cadence     | What happens                                                         |
| ----------- | -------------------------------------------------------------------- |
| **Weekly**  | One scan per ~7 days, on a stable weekday derived from the domain id |
| **Monthly** | One scan per ~30 days, on a stable day of the month                  |
| **Never**   | Manual only — the scheduler skips this domain                        |

To change cadence, go to **Domains → {your domain}** and pick from the
**Scan cadence** dropdown. The change applies on the next scheduled run.

## What gets scanned

The scanner runs URL discovery before crawling:

1. Fetch `/sitemap.xml`. If present, follow it (including `<sitemapindex>`
   for sites with multiple sitemaps).
2. If no sitemap, fetch `/robots.txt` and follow any `Sitemap:` directives.
3. If neither yields anything, scan just the apex URL.

Discovered URLs are filtered to same-host only (or subdomains of the apex)
and capped at **150 URLs per scan**. If your site has more pages than that
and you want broader coverage, [get in touch](mailto:support@effectdigital.com)
— per-domain caps are configurable.

## What the scanner observes

For each URL it crawls, the scanner captures:

- **Cookies set** via `Set-Cookie` headers OR `document.cookie` writes
- **`<script src=…>`** tags loaded
- **`<iframe src=…>`** elements rendered
- **Network requests** fired by any third-party script (XHR, fetch, beacons)

Cookies are resolved through:

1. Per-domain overrides (highest priority)
2. Per-organisation overrides
3. The canonical cookie database (curated + OpenCookieDatabase imports)

Anything that doesn't match lands in the [review queue](./troubleshooting#unknown-cookies-in-the-review-queue).

## Material vs cosmetic publishes

Unrelated to scans but relevant when you're updating the banner config from
the editor:

- **Material changes** — GCM defaults, geo rules, GPC honouring, cross-subdomain
  consent, locales enabled. Publishing these increments `materialVersion`,
  which **re-prompts every consented visitor** on their next visit.
- **Cosmetic changes** — colours, copy, layout, default locale, re-prompt-after-days.
  These don't trigger a re-prompt; visitors keep their existing consent.

The admin banner editor surfaces a **Pending changes** panel above the
publish button showing exactly which fields will trigger a re-prompt, so you
never accidentally invalidate consent across a portfolio.
