# Cookie policy generation

Effect Insight generates a per-domain cookie policy from the latest
scan findings, your canonical cookie database, and any per-org
overrides. The policy is the human-readable disclosure regulators
expect on your privacy page.

## Embedding the policy on your site

Each domain has a self-contained HTML page you can embed in an
iframe. Find it on the domain detail page in admin under **Cookie
policy** — click **Copy embed snippet** to grab the markup.

It looks like:

```html
<iframe
  src="https://api.effectinsight.com/api/cookie-policy/pk_xxx.html"
  title="Cookies in use"
  style="width: 100%; min-height: 600px; border: 0;"
></iframe>
```

The HTML is self-contained — every style is inlined, so it renders
inside even the strictest CSP host pages without nonce wiring. Light
and dark colour schemes are both supported automatically.

The iframe URL uses the domain's **Public key** (`pk_…`), the same
one your banner embed uses. It's safe to expose publicly — there's
no tenant data leak risk.

::: tip Cache window
The embedded policy is cached on Cloudflare's edge for up to one
hour. A new scan can take up to an hour to surface in the iframe
your visitors see.
:::

## How the policy is assembled

For each cookie observed in the most recent completed scan:

1. Dedupes by cookie name — multiple finds of the same `_ga` cookie
   collapse to one row.
2. Resolves the category through the classifier (domain override →
   org override → canonical database).
3. Buckets into the four standard categories — Necessary,
   Preferences, Statistics, Marketing.
4. Anything the classifier can't categorise lands in an **"Unknown —
   pending review"** bucket so legal can see it rather than have it
   silently disappear. The same cookies surface in the
   [review queue](./troubleshooting/consent#unknown-cookies-in-the-review-queue)
   for one-click classification.

Domains that have never been scanned show a "no cookies detected
yet" placeholder instead of an empty table.

Third-party scripts and iframes observed by the scanner are
deliberately excluded — the policy is a cookie policy, not a
third-party-services policy. A separate vendors section will land
when the vendor model ships (see [Vendor management](./vendors)).

## Refreshing the policy

The embedded iframe always reflects the most recent **completed**
scan for the domain. To force a refresh:

1. Trigger a fresh scan — see [Triggering scans](./triggering-scans).
2. Wait for the scan to complete (a minute or two, depending on
   sitemap size).
3. Wait up to an hour for the edge cache to roll over, or hard-
   refresh the iframe in dev tools to see it immediately.

Or wait for the next weekly/monthly scheduled scan.

## Customising the bucket descriptions

The category descriptions baked into the embedded HTML are platform
defaults written to be regulator-safe. They aren't currently
configurable per organisation. If your legal team needs bespoke
copy, [get in touch](mailto:support@effectdigital.com) — we'll
either render the JSON variant for your own template or add per-org
overrides when there's a real need.

## Limitations today

- **No cookie expiry data.** The scanner doesn't currently capture
  `Max-Age` / `Expires`, so the policy doesn't disclose lifetimes.
  Worth adding when the next-gen scanner work lands.
- **No vendor / data-controller info.** Hooked to the canonical
  cookies table only. The vendor model will fill this in when
  shipped — see [Vendor management](./vendors).
- **No per-locale copy.** Single-language render today — the page
  shows English text regardless of your banner's default locale.

## Related

- [Cookie database management](./cookie-management) — promoting
  imports, creating overrides, triaging unknowns.
- [Triggering scans](./triggering-scans) — what populates the
  cookie inventory the policy is built from.
