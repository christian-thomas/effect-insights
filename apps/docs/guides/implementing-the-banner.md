# Implementing the banner

Paste a single script tag near the top of `<head>` on every page of your site.
The loader fetches the latest published config from the platform edge and
renders the banner before any tracking scripts run.

## The embed snippet

```html
<script
  src="https://assets.effectinsight.com/loader.js"
  data-domain-id="<your-domain-public-key>"
  async
></script>
```

Replace `<your-domain-public-key>` with the `publicKey` shown on the domain's
detail page in the admin (`https://admin.effectinsight.com/domains/{id}`). It looks
like `pk_AbCdEf…` — copy the **Public key** field exactly.

## Where to paste it

| Stack                  | Where                                                                                               |
| ---------------------- | --------------------------------------------------------------------------------------------------- |
| Static HTML            | Inside `<head>`, before any analytics scripts                                                       |
| Next.js                | `pages/_document.tsx` or `app/layout.tsx` — use `<Script>` with `strategy="beforeInteractive"`      |
| Nuxt                   | `nuxt.config.ts` → `app.head.script` array                                                          |
| WordPress              | `wp_head` hook in your theme, or via a head-injector plugin (GTM, Insert Headers and Footers, etc.) |
| Shopify                | Online Store → Themes → Edit code → `theme.liquid`, inside `<head>`                                 |
| Custom server-rendered | Wherever your `<head>` template lives                                                               |

It must load **before** anything you intend to gate on consent. The banner
sets Google Consent Mode v2 defaults to `denied` synchronously on first paint,
so any GA / Ads tags that fire afterwards will respect the visitor's choice.

## Blocking third-party scripts

The banner can't unblock what wasn't blocked in the first place. Mark every
non-essential script with `type="text/plain"` and a category:

```html
<!-- BEFORE: tracking script runs on every page load -->
<script src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX" async></script>

<!-- AFTER: tracking script waits for consent -->
<script
  type="text/plain"
  data-category="statistics"
  src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"
  async
></script>
```

When the visitor grants the matching category, cmp.js replaces the `text/plain`
tag with a real `<script>` and the browser fetches + executes it. See
[Blocking third-party scripts](./blocking-scripts) for the per-vendor recipe.

## Optional attributes

These go on the same `<script>` tag as the embed.

| Attribute   | When to use it                                                                                         |
| ----------- | ------------------------------------------------------------------------------------------------------ |
| `nonce="…"` | Required if your site uses a strict CSP with a nonce allow-list. See [Content Security Policy](./csp). |

A handful of further attributes (`data-cmp-base`, `data-cmp-config`,
`data-cmp-preview`) exist for internal preview tooling. They're not
intended for production embeds — ignore them.

## What the visitor sees

- **First visit**: the banner renders centred (modal) / docked (bar) / pinned
  (corner) depending on the layout you've published.
- **Accept all / Reject all / Customise**: visitor's choice is written to a
  `_effectinsight` cookie + POSTed to the edge. GCM signals update synchronously,
  blocked scripts unblock, the banner disappears.
- **Subsequent visits**: cmp.js reads the cookie, replays the GCM signals,
  unblocks the right scripts, and **doesn't render the banner again** —
  unless the publisher made a [material change](./banner-configuration#material-vs-cosmetic-changes)
  or `behaviour.rePromptAfterDays` (12 months by default) has elapsed.

If the host page wants to give the visitor a "Cookie preferences" link, see
[`window.cmp`](./javascript-api#window-cmp) — `openBanner()`, `withdraw()`,
and `onConsentChange()` are the most-used methods.

## How long until visitors see it?

- **First publish on a domain**: instant. The edge reads the latest
  published config on every request; the next page load anywhere on the
  embedded site picks up the new config.
- **Subsequent publishes**: the same — there's no CDN warm-up, no
  cache-bust step. The cmp.js bundle is served immutably; the _config_
  it loads is the freshest version available at request time.

## Troubleshooting

- Banner doesn't show on first visit → see [Troubleshooting](./troubleshooting/consent#banner-doesnt-render).
- Visitor clicks accept but tracking scripts don't run → check the
  `data-category` attribute matches what your published banner config offers
  (`necessary` / `preferences` / `statistics` / `marketing`).
- Strict CSP blocks the banner → see [Content Security Policy](./csp).
