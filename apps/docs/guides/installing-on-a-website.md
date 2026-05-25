# Installing on a website

Stack-specific recipes for the script-tag install. Pick yours and copy
the snippet — the contents are the same as
[Implementing the banner](./implementing-the-banner), but the
_where to put it_ differs per stack.

For Google Tag Manager installs, see
[Installing via Google Tag Manager](./installing-via-gtm) instead —
it's a different pattern.

Replace `pk_REPLACE_ME` with the **Public key** from your domain's
detail page in admin.

---

## WordPress

### Option 1 — `wp_head` action in your theme

If you have full control over the theme, hook into `wp_head` from your
theme's `functions.php`:

```php
<?php
add_action('wp_head', function (): void {
    echo '<script src="https://assets.effectinsight.com/loader.js" '
       . 'data-domain-id="pk_REPLACE_ME" async></script>';
}, 1); // priority 1 — fire as early as possible in <head>
```

The priority 1 matters — the banner must load before any analytics
plugin shoves its own tags into the head.

### Option 2 — "Insert Headers and Footers" plugin (no theme edit)

If you can't edit theme files:

1. Install the [Insert Headers and Footers](https://wordpress.org/plugins/insert-headers-and-footers/)
   plugin (or any equivalent — WPCode, HeadFooter Code, etc).
2. Paste the embed snippet from
   [Implementing the banner](./implementing-the-banner#the-embed-snippet)
   into the **Scripts in Header** field.
3. Save.

### What about Yoast, RankMath, GA4 plugins?

Those plugins inject their analytics scripts directly. cmp.js can only
gate them if they're marked with `type="text/plain"` —
[Blocking third-party scripts](./blocking-scripts) explains the
pattern, but most analytics plugins don't expose that markup. Cleanest
fix: switch those plugins to "manually inject the tag via
GTM" mode (most have this option) and use
[Installing via Google Tag Manager](./installing-via-gtm) instead.

---

## Shopify

1. Shopify admin → **Online Store → Themes → ⋯ → Edit code**.
2. Open `layout/theme.liquid`.
3. Find the `<head>` tag. As early as possible — before any
   `{{ content_for_header }}` — paste:

   ```liquid
   <script
     src="https://assets.effectinsight.com/loader.js"
     data-domain-id="pk_REPLACE_ME"
     async
   ></script>
   ```

4. Save.

Shopify's built-in `{{ content_for_header }}` injects Shopify's own
trackers (Facebook Pixel via the Facebook channel, Google channel,
etc). cmp.js loads _before_ those, so it sets GCM defaults to denied
before any Shopify-managed tag reads them. Visitor consents trigger
the GCM signals; Shopify's own tags pick them up.

If you have **third-party apps** that inject their own pixels (Klaviyo,
Postscript, etc.), check whether they honour GCM v2. The big ones do;
the long tail doesn't. For non-GCM-aware apps you'll need to gate
them via [the script-blocking pattern](./blocking-scripts).

---

## Next.js (App Router, 13+)

In `app/layout.tsx`:

```tsx
import Script from 'next/script';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <head>
        <Script
          src="https://assets.effectinsight.com/loader.js"
          data-domain-id="pk_REPLACE_ME"
          strategy="beforeInteractive"
          async
        />
      </head>
      <body>{children}</body>
    </html>
  );
}
```

`strategy="beforeInteractive"` matters — it inlines the tag into the
SSR'd HTML head, so the banner mounts before React hydrates. The
default `afterInteractive` strategy would race against your analytics
scripts.

### Strict CSP with `next.config.js`

If you've set up a strict CSP via `headers()`, add the nonce. Next.js'
docs cover the [middleware-based nonce flow](https://nextjs.org/docs/app/guides/content-security-policy)
— pass the nonce to `Script` via the standard `nonce` prop, and add a
`<meta name="csp-nonce">` to `app/layout.tsx`:

```tsx
import { headers } from 'next/headers';

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const nonce = (await headers()).get('x-nonce') ?? '';
  return (
    <html lang="en">
      <head>
        <meta name="csp-nonce" content={nonce} />
        <Script
          src="https://assets.effectinsight.com/loader.js"
          data-domain-id="pk_REPLACE_ME"
          strategy="beforeInteractive"
          nonce={nonce}
          async
        />
      </head>
      <body>{children}</body>
    </html>
  );
}
```

See [Content Security Policy](./csp) for what the CSP header should
actually contain.

### Pages Router (legacy)

In `pages/_document.tsx`:

```tsx
import Document, { Html, Head, Main, NextScript } from 'next/document';

export default class MyDocument extends Document {
  render() {
    return (
      <Html lang="en">
        <Head>
          <script
            src="https://assets.effectinsight.com/loader.js"
            data-domain-id="pk_REPLACE_ME"
            async
          />
        </Head>
        <body>
          <Main />
          <NextScript />
        </body>
      </Html>
    );
  }
}
```

---

## Nuxt (3 / 4)

In `nuxt.config.ts`:

```ts
export default defineNuxtConfig({
  app: {
    head: {
      script: [
        {
          src: 'https://assets.effectinsight.com/loader.js',
          'data-domain-id': 'pk_REPLACE_ME',
          async: true,
          // tagPosition: 'head' is the default for app.head.script
        },
      ],
    },
  },
});
```

Nuxt 3+ renders the script into the SSR'd HTML head; the banner loads
before client-side hydration runs.

### With a strict CSP (Nuxt Security module)

If you're using
[`nuxt-security`](https://nuxt-security.vercel.app/), enable the
nonce feature and Nuxt will stamp the right nonce on the script
automatically. cmp.js' fallback (`document.currentScript.nonce`)
picks it up.

---

## React (CRA, Vite, generic)

The script tag goes in your `index.html`, as high in `<head>` as
possible:

```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <script
      src="https://assets.effectinsight.com/loader.js"
      data-domain-id="pk_REPLACE_ME"
      async
    ></script>
    <!-- everything else after the banner -->
    <title>…</title>
    <link rel="icon" href="/favicon.ico" />
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.tsx"></script>
  </body>
</html>
```

---

## Vue (Vite, standalone)

Same as React-Vite — paste in `index.html`:

```html
<script src="https://assets.effectinsight.com/loader.js" data-domain-id="pk_REPLACE_ME" async></script>
```

---

## SvelteKit

In `src/app.html`:

```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <script
      src="https://assets.effectinsight.com/loader.js"
      data-domain-id="pk_REPLACE_ME"
      async
    ></script>
    %sveltekit.head%
  </head>
  <body data-sveltekit-preload-data="hover">
    <div style="display: contents">%sveltekit.body%</div>
  </body>
</html>
```

---

## Astro

In `src/layouts/Base.astro` (or whatever your root layout is):

```astro
---
// no frontmatter needed
---
<!doctype html>
<html lang="en">
  <head>
    <script
      src="https://assets.effectinsight.com/loader.js"
      data-domain-id="pk_REPLACE_ME"
      async
      is:inline
    ></script>
    <slot name="head" />
  </head>
  <body>
    <slot />
  </body>
</html>
```

`is:inline` matters — by default Astro processes script tags as
ESM modules, which changes their behaviour. `is:inline` outputs the
tag verbatim, which is what we want.

---

## Hugo / Jekyll / Eleventy / static-site generators

In your base layout / template / `_layouts/default.html` — paste
into `<head>`:

```html
<script src="https://assets.effectinsight.com/loader.js" data-domain-id="pk_REPLACE_ME" async></script>
```

---

## Drupal

Drupal 9+ uses libraries. Create
`themes/custom/{theme}/{theme}.libraries.yml`:

```yaml
cmp:
  header: true
  js:
    https://assets.effectinsight.com/loader.js:
      type: external
      attributes:
        async: true
        data-domain-id: pk_REPLACE_ME
```

Then attach the library in `{theme}.theme`:

```php
function THEMENAME_preprocess_html(&$variables) {
  $variables['#attached']['library'][] = 'THEMENAME/cmp';
}
```

---

## Webflow

1. Webflow Designer → **Project Settings → Custom Code → Head Code**.
2. Paste:

   ```html
   <script
     src="https://assets.effectinsight.com/loader.js"
     data-domain-id="pk_REPLACE_ME"
     async
   ></script>
   ```

3. **Save Changes** and **Publish**.

---

## Squarespace

1. **Settings → Advanced → Code Injection → Header**.
2. Paste the embed snippet.
3. **Save**.

---

## Wix

Wix's restricted runtime makes script injection awkward. Use
**Settings → Custom Code → Add custom code**, set:

- **Code**: the embed snippet
- **Add code to pages**: All pages
- **Place code in**: Head
- **Load code on each new page**: yes

Wix's own analytics tags run outside the CMP's reach, so consent
gating in Wix has limits — you'll need to combine cmp.js with
Wix's built-in consent banner for the platform-level cookies.

---

## Stack not listed?

The embed snippet works on anything that lets you put a `<script>` tag
into `<head>`. If you can't find the right field in your stack's admin,
the rule is:

1. Get it into `<head>` (not `<body>`).
2. Get it **before** anything that tracks (analytics, ads pixels,
   chat widgets).
3. Use `async` — don't block first paint.

If you've checked all three and still get nowhere, see
[Troubleshooting → Banner doesn't render](./troubleshooting/consent#banner-doesnt-render).
