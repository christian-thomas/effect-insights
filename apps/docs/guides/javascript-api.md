# JavaScript API surface

What cmp.js exposes to host-page code, and — more often — what it
_doesn't_. Read this if you want to gate your own JavaScript on
consent, or if you're writing custom analytics that needs to react
when the visitor accepts.

## What's there today

### `window.dataLayer`

cmp.js pushes Google Consent Mode v2 events to `window.dataLayer`,
the standard channel for Google Tag Manager + GA4. Every other
script on the page that integrates with GCM picks up the right
signals automatically.

```js
// At cmp.js boot, before any banner render:
window.dataLayer.push([
  'consent',
  'default',
  {
    ad_storage: 'denied',
    analytics_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    functionality_storage: 'granted',
    personalization_storage: 'denied',
    security_storage: 'granted',
  },
]);

// On visitor consent (after accept/reject/save):
window.dataLayer.push([
  'consent',
  'update',
  {
    analytics_storage: 'granted', // based on visitor's choices
    ad_storage: 'denied',
    // ...etc
  },
]);
```

If you're using GTM, see [Installing via Google Tag Manager](./installing-via-gtm)
for how to gate your existing tags on these signals.

### The consent cookie

cmp.js writes consent to a cookie named `_effectcmp`. The value is a
URL-encoded JSON object:

```js
{
  "anonymousId": "uuid…",
  "materialVersion": 3,
  "categories": {
    "necessary": true,
    "preferences": false,
    "statistics": true,
    "marketing": false
  },
  "consentedAt": "2026-05-24T12:00:00.000Z"
}
```

You can read this from your own code:

```ts
const cookie = document.cookie.split('; ').find((c) => c.startsWith('_effectcmp='));

if (cookie) {
  const stored = JSON.parse(decodeURIComponent(cookie.split('=')[1]));
  if (stored.categories.statistics) {
    // load analytics tool
  }
}
```

**Don't write to this cookie from your own code.** If you do, cmp.js
will replay your forged state to GCM on the next page load but
nothing will be POSTed to the consent log — you'll have a visitor
the platform never recorded consent for. Always go through the
banner.

### Blocked scripts that aren't yet released

If you have `<script type="text/plain" data-category="…">` tags on
the page that haven't been consented to yet, they're inert. Your own
JavaScript can find them via the DOM:

```ts
document.querySelectorAll('script[type="text/plain"][data-category]').forEach((tag) => {
  console.log('blocked', tag.dataset.category, tag.src || tag.textContent);
});
```

This is sometimes useful for debugging your own embed list.

## What's NOT there

cmp.js doesn't currently expose a public `window.cmp` API. The
following all sit in the wishlist:

- `window.cmp.getConsent()` — programmatic read of the current
  consent state (without parsing the cookie yourself).
- `window.cmp.openBanner()` — re-open the banner so the visitor can
  change their mind. Today they have to clear cookies + reload.
- `window.cmp.withdraw()` — revoke consent without re-opening the
  banner.
- `window.cmp.onConsentChange((state) => …)` — subscribe to consent
  events.

::: tip Need any of these?
If you're an Effect Digital client who needs one of these for an
integration, [get in touch](mailto:support@effectdigital.com). The
absence is "we haven't prioritised it", not "we don't think it
should exist".
:::

## Listening for the consent moment

Until we ship a proper subscription API, the cleanest hook is
listening for `dataLayer` pushes:

```ts
// Get the current dataLayer or create one
window.dataLayer = window.dataLayer || [];

// Wrap dataLayer.push to react to consent events
const originalPush = window.dataLayer.push.bind(window.dataLayer);
window.dataLayer.push = (...args) => {
  for (const arg of args) {
    if (Array.isArray(arg) && arg[0] === 'consent' && arg[1] === 'update') {
      const state = arg[2];
      if (state.analytics_storage === 'granted') {
        // user just granted analytics — fire your event
      }
    }
  }
  return originalPush(...args);
};
```

Caveat: GTM and other GCM-aware libraries also push to `dataLayer`,
so test that your wrapper doesn't break their flow.

## Server-side reads

If your server can see the consent cookie (same domain, no
cross-site issues), parse it the same way as the client-side example
above. Don't put it in headers or pass it around — it's a session
anonymous identifier, but the spirit of the design is that the
_server_ doesn't make consent decisions; the _banner_ does, and the
_server_ receives the audit trail via the consent worker.
