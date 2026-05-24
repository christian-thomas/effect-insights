# Content Security Policy

Strict CSP is one of the most common reasons a banner silently fails to
appear on production. cmp.js supports nonce-based CSP out of the box — you
just need to give it the nonce.

## What cmp.js needs

A strict CSP with `script-src 'nonce-…'` blocks every script that doesn't
carry a matching nonce, including:

1. The cmp.js embed script itself
2. The `<style>` element cmp.js injects to render the banner
3. Every blocked `<script type="text/plain">` cmp.js re-injects on consent

cmp.js handles (2) and (3) for you. You handle (1) the same way you'd nonce
any other script.

## How to provide the nonce

cmp.js looks for the nonce in three places, in order:

### Option 1 — `<meta name="csp-nonce">` (recommended)

```html
<head>
  <meta name="csp-nonce" content="<your-per-request-nonce>" />

  <script
    src="https://assets.tracewatch.io/loader.js"
    data-domain-id="pk_…"
    nonce="<your-per-request-nonce>"
    async
  ></script>
</head>
```

This is the documented convention used by Rails, Laravel (via spatie/laravel-csp),
Next.js, Symfony, and most major frameworks. cmp.js picks the nonce up
automatically and stamps it on every style + script it creates.

### Option 2 — `document.currentScript.nonce`

If your stack already nonces every script tag but doesn't emit the meta
tag, cmp.js falls back to reading the nonce off its own script element
via the DOM `nonce` property (the HTML `nonce="…"` attribute is wiped by
the browser after parsing — that's intentional, to prevent CSS-based
exfiltration — but the property is preserved).

So this is also enough:

```html
<script
  src="https://assets.tracewatch.io/loader.js"
  data-domain-id="pk_…"
  nonce="<your-per-request-nonce>"
  async
></script>
```

### Option 3 — pass nothing

If you don't run strict CSP, just embed without a nonce. cmp.js detects
nothing and omits the `nonce` attribute on injected elements. Standard
non-CSP browsers just run the styles + scripts.

## Recommended CSP directives

For a typical site running cmp.js:

```http
Content-Security-Policy:
  default-src 'self';
  script-src 'self' 'nonce-{generated-per-request}' https://assets.tracewatch.io;
  style-src 'self' 'nonce-{generated-per-request}';
  connect-src 'self' https://assets.tracewatch.io;
  img-src 'self' data: https:;
```

Adjust to taste — most clients also add their analytics origins to
`script-src` (`https://www.googletagmanager.com`, `https://www.google-analytics.com`,
`https://connect.facebook.net`, etc.).

## Common gotchas

- **`connect-src 'self'` blocks the config fetch.** cmp.js fetches its
  config from `assets.tracewatch.io`. You must explicitly allow that origin
  in `connect-src` or the banner will silently fail to load.
- **Missing nonce on cmp.js itself.** Without (1) above, the browser
  refuses to execute cmp.js, so nothing else matters. Check `view-source:`
  on a production page — the `<script>` tag should carry `nonce="…"`.
- **`nonce` attribute looks empty in dev tools.** Browsers wipe the
  attribute after parsing. The DOM property is what's actually checked.
  Use the **Console** tab → `document.querySelector('[data-domain-id]').nonce`
  to confirm.
- **CSP report-only mode.** If you're rolling out CSP, set
  `Content-Security-Policy-Report-Only` first so violations only get
  logged, not enforced. cmp.js will show up loud and clear in the
  console (`Refused to apply inline style…`) until you wire the nonce
  through.

## Verifying it works

Open dev tools → Console on a production page where the banner _should_
show but doesn't. If CSP is the cause you'll see one of:

```
Refused to load the script 'https://assets.tracewatch.io/loader.js'
because it violates the following Content Security Policy directive…

Refused to apply inline style because it violates the following CSP directive…

Refused to execute inline script because it violates the following CSP directive…
```

All three are nonce / origin allow-list issues. Each maps to one of the
recommendations above.

If you see no CSP errors but the banner still doesn't render, jump to
[Troubleshooting](./troubleshooting#banner-doesnt-render).
