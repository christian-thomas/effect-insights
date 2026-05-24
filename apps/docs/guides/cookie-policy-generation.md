# Cookie policy generation

::: warning Not shipped yet
This feature is on the roadmap — not available today. This page exists so
the future shape is documented and discoverable. If you're an Effect Digital
client who needs cookie-policy generation now, [get in touch](mailto:support@effectdigital.com).
:::

## What it'll do

Once shipped, Effect CMP will generate a cookie-policy page for each
domain automatically, kept in sync with the latest scan.

For each cookie observed on your site, the policy will surface:

- Cookie name + the page it was first seen on
- Vendor + purpose (from the canonical database or your override)
- Category (Necessary / Preferences / Statistics / Marketing)
- Expiry duration
- Cross-site / first-party origin

The page will be served from `https://assets.effectcmp.com/policy/<publicKey>`
and embeddable on your site in one of two ways:

```html
<!-- Option 1: embed as an iframe -->
<iframe
  src="https://assets.effectcmp.com/policy/pk_…"
  style="width: 100%; min-height: 600px; border: 0;"
  title="Cookie policy"
></iframe>

<!-- Option 2: download static HTML for strict-embed clients -->
<!-- (CSP-friendly, no third-party iframe needed) -->
```

The policy will:

- Update automatically after every scan completes
- Carry the same multi-locale copy you've configured on the banner
- Honour your custom domain (so it renders at `cmp.<client>.com` rather
  than the platform domain) once the per-org CDN feature lands

## What you do today

Until this lands, you write the cookie policy yourself. The
**Cookies → Canonical** tab in admin lists every categorised cookie you'd
need to disclose; the **Review queue** tab shows the ones you haven't
classified yet. Export the relevant rows and paste them into your
existing policy template.

## ETA

Targeted for v1.1. Track progress in the [open list of the handoff doc](https://github.com/effect-digital/cmp-project)
or ask your account manager.
