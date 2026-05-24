# Cookie policy generation

Effect CMP generates a per-domain cookie policy from the latest scan
findings + your canonical cookie database + per-org overrides. Two
endpoints are available — one structured for programmatic use, one
ready-to-embed HTML.

## Endpoints

### `GET /api/cookie-policy/{publicKey}.html`

Public, unauthenticated. Returns a self-contained HTML page styled for
embedding in an iframe. Looked up by the domain's `public_key` so
embedders never need to know the internal UUID and there's no tenant
data leak risk.

```html
<iframe
  src="https://api.effectcmp.com/api/cookie-policy/pk_xxx.html"
  title="Cookies in use"
  style="width: 100%; min-height: 600px; border: 0;"
></iframe>
```

The HTML inlines all styles (no external requests) so it renders inside
strict-CSP host pages without nonce wiring. Light and dark colour
schemes both supported via `prefers-color-scheme`.

Cache: `public, max-age=3600, stale-while-revalidate=86400`. A new scan
takes up to an hour to surface to embedded iframes.

### `GET /api/domains/{domain}/cookie-policy`

Tenant-scoped JSON. Returns the same data as the HTML endpoint but
structured for clients that want to render the policy with their own
templates. Requires Sanctum auth — use this when integrating from a
trusted back-end, not from the embedding website.

```json
{
  "data": {
    "domain": { "fqdn": "example.com", "publicKey": "pk_..." },
    "generatedAt": "2026-05-24T12:00:00+00:00",
    "scan": { "id": "uuid", "finishedAt": "2026-05-24T11:30:00+00:00" },
    "categories": [
      {
        "category": "necessary",
        "label": "Strictly necessary",
        "description": "Required for the site to function. Cannot be disabled.",
        "cookies": [
          {
            "name": "XSRF-TOKEN",
            "party": "first",
            "domainSetOn": ".example.com",
            "purpose": "Laravel CSRF token",
            "firstSeenUrl": "https://example.com/login"
          }
        ]
      }
    ]
  }
}
```

## How the policy is assembled

For each cookie observed in the most recent **completed** scan
(`status = completed`), the builder:

1. Dedupes by cookie name. Multiple finds of the same `_ga` cookie
   collapse to one row.
2. Resolves category through `CookieCategoryResolver` — domain override
   → org override → canonical DB.
3. Buckets into the four standard categories
   (necessary / preferences / statistics / marketing).
4. Cookies the resolver couldn't categorise land in an
   **"Unknown — pending review"** bucket so legal can see them rather
   than have them silently disappear. They also surface in the admin's
   [review queue](./troubleshooting#unknown-cookies-in-the-review-queue)
   for one-click override.

`script` and `iframe` scan findings are deliberately excluded — the
policy is a cookie policy, not a third-party-services policy. Future
versions may add a separate vendors section once the vendor model
lands.

Domains that have never been scanned return an empty `categories[]`
array. The HTML view shows a "no cookies detected yet" placeholder
instead of an empty table.

## Customising the bucket descriptions

The category descriptions returned by the policy endpoint are the
platform defaults. If you need bespoke wording (jurisdictional copy,
client tone of voice), use the JSON endpoint and render with your own
template.

Customising the descriptions for the public HTML endpoint isn't
configurable today — it ships with one set of regulator-safe defaults.
File an issue if your legal team needs to override them in-place.

## Triggering a re-generation

The policy reflects the most recent completed scan. Trigger a fresh
scan via [Triggering scans](./triggering-scans) (manual button on the
domain detail page, or the API), or wait for the next scheduled
weekly/monthly run. The 1-hour cache means it can take an hour for new
findings to appear in an embedded iframe.

## Limitations

- **No cookie expiry data.** The scanner doesn't currently capture
  `Max-Age` / `Expires` so the policy doesn't disclose lifetimes.
  Worth adding when the next-gen scanner work lands.
- **No vendor / data-controller info.** Hooked to the canonical
  cookies table only; the vendor model (#9 on the open list) will fill
  this in when shipped.
- **No per-locale copy.** Single-language render today — the page
  uses English text regardless of `localeDefault`. Localisation is
  trivial to add when first needed.
