# Blocking third-party scripts

For the banner to actually gate tracking, the tracking scripts on your
site must be marked as blocked. cmp.js can only unblock what was blocked
in the first place — it has no way to "stop" a script that already ran.

## The pattern

Add `type="text/plain"` + `data-category="…"` to every non-essential
script:

```html
<!-- ❌ This runs unconditionally on every page load -->
<script src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX" async></script>

<!-- ✅ This waits for consent -->
<script
  type="text/plain"
  data-category="statistics"
  src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"
  async
></script>
```

The browser refuses to execute a script with a non-standard MIME type, so
the tag is inert until cmp.js promotes it. On consent, cmp.js walks the
DOM, finds every `script[type="text/plain"][data-category="…"]` whose
category was granted, clones it as a real `<script>`, and replaces the
original. The browser then fetches + executes it.

## The four categories

| Category      | What goes here                                                                                         |
| ------------- | ------------------------------------------------------------------------------------------------------ |
| `necessary`   | Auth, session, security, CSRF tokens, payment processing. Always granted — visitors can't opt out.     |
| `preferences` | Language / region / theme preference cookies, A/B test allocation, remember-me.                        |
| `statistics`  | First-party analytics: GA4, Hotjar, Plausible, internal event pipelines. Anything anonymised.          |
| `marketing`   | Ad tags, retargeting pixels, social embeds that drop cookies (Facebook, LinkedIn, Twitter, Pinterest). |

Use the exact strings above as the `data-category` value. Any other value
is silently ignored.

## Common vendors

### Google Tag Manager + GA4

```html
<script
  type="text/plain"
  data-category="statistics"
  src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"
  async
></script>
<script type="text/plain" data-category="statistics">
  window.dataLayer = window.dataLayer || [];
  function gtag() { dataLayer.push(arguments); }
  gtag('js', new Date());
  gtag('config', 'G-XXXXX');
</script>
```

GCM v2 defaults are pushed to `dataLayer` by cmp.js before any of your
scripts run, so GA4 picks the right signals up automatically once granted.

### Google Tag Manager (GTM container)

```html
<script type="text/plain" data-category="statistics">
  (function (w, d, s, l, i) {
    w[l] = w[l] || [];
    w[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
    var f = d.getElementsByTagName(s)[0];
    var j = d.createElement(s);
    j.async = true;
    j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i;
    f.parentNode.insertBefore(j, f);
  })(window, document, 'script', 'dataLayer', 'GTM-XXXXX');
</script>
```

Inside GTM you'll still want triggers gated by consent state — GTM has
its own consent-aware triggers that respect the GCM signals cmp.js
pushes.

### Meta / Facebook Pixel

```html
<script type="text/plain" data-category="marketing">
  !function (f, b, e, v, n, t, s) { /* … standard pixel snippet … */ }
  fbq('init', 'YOUR-PIXEL-ID');
  fbq('track', 'PageView');
</script>
```

### LinkedIn Insight Tag

```html
<script type="text/plain" data-category="marketing">
  _linkedin_partner_id = "XXXXXX";
  /* … standard LinkedIn snippet … */
</script>
```

### Hotjar

```html
<script type="text/plain" data-category="statistics">
  /* … standard Hotjar snippet … */
</script>
```

### Intercom

```html
<script type="text/plain" data-category="preferences">
  /* … standard Intercom snippet … */
</script>
```

Intercom sits in _preferences_ rather than _statistics_ because it's a
support tool that remembers the visitor across visits — it's not purely
analytics.

## SPAs and dynamically-injected scripts

If your app loads new third-party scripts after the initial page render
(e.g. analytics fired on route change in a SPA), wrap each one in a
`type="text/plain"` tag at injection time. cmp.js' `MutationObserver`
watches for new blocked scripts and unblocks them automatically as they
appear, so the same pattern works for late-arrivals.

```ts
// In a Nuxt / React / Vue route-change hook:
const script = document.createElement('script');
script.type = 'text/plain';
script.dataset.category = 'statistics';
script.src = 'https://www.googletagmanager.com/gtag/js?id=G-XXXXX';
script.async = true;
document.head.appendChild(script);
// cmp.js sees it via MutationObserver and promotes to real <script> if
// the visitor has already granted statistics consent.
```

## What if I miss one?

If a script runs before consent because you forgot to block it, the
scanner will catch it on the next run and flag the cookies it sets in
the [review queue](./troubleshooting#unknown-cookies-in-the-review-queue).
You'll see something like _"`_ga_ABC123` observed on `https://your-site.com/`
— uncategorised"_ — that's your prompt to go block the underlying script
in the source.
