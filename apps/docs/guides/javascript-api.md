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

cmp.js writes consent to a cookie named `_effectinsight`. The value is a
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
const cookie = document.cookie.split('; ').find((c) => c.startsWith('_effectinsight='));

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

### `window.cmp`

cmp.js installs a public API at `window.cmp` as soon as the script
runs — before the config has loaded. Methods that need the config
(everything except `getConsent`, `isReady`, `ready`, and
`onConsentChange`) queue any pre-ready calls and replay them once
boot finishes.

```ts
interface CmpApi {
  // Current stored consent or null if visitor never consented. Reads
  // straight from the cookie — no internal cache.
  getConsent(): ConsentCategories | null;

  // True once cmp.js has loaded the config (whether that ended in a
  // banner render, a short-circuit on stored consent, a GPC auto-
  // deny, or a 404 from the edge).
  isReady(): boolean;

  // Fires once isReady() is true. Invoked on the next microtask if
  // already ready.
  ready(callback: () => void): void;

  // Re-opens the banner UI even for visitors with existing consent.
  // Wire this to a "Cookie preferences" footer link.
  openBanner(): void;

  // Records a `withdrawn` event with everything denied + re-opens the
  // banner so the visitor can re-decide. GDPR Article 7(3) requires
  // the act of withdrawing to be as easy as the act of giving.
  withdraw(): void;

  // Shortcuts. acceptAll() and rejectAll() don't render the banner —
  // they record the choice and notify subscribers, no UI.
  acceptAll(): void;
  rejectAll(): void;

  // Arbitrary category mix. `necessary` is always coerced to true.
  setConsent(categories: Partial<ConsentCategories>): void;

  // Subscribe to consent changes. Returns an unsubscribe fn.
  onConsentChange(
    listener: (event: {
      type: 'granted' | 'denied' | 'changed' | 'withdrawn';
      categories: ConsentCategories;
      consent: StoredConsent | null;
    }) => void,
  ): () => void;
}
```

#### "Withdraw consent" footer link

```html
<a href="#" onclick="window.cmp?.withdraw(); return false;"> Cookie preferences </a>
```

If a visitor clicks this they get a `withdrawn` consent event recorded
in the audit log AND the banner re-opens so they can re-decide.

#### Just re-opening the banner without withdrawing

```html
<a href="#" onclick="window.cmp?.openBanner(); return false;"> Cookie preferences </a>
```

Use this when you want the visitor to be able to fine-tune their
choices without first revoking everything.

#### Reacting to consent changes

```ts
// Wait until cmp.js is loaded — onConsentChange itself is available
// pre-ready but listeners only fire on actual consent events.
window.cmp?.ready(() => {
  window.cmp?.onConsentChange((event) => {
    if (event.type === 'granted' || event.categories.statistics) {
      // load your analytics tool
    }
  });

  // First-paint check — the visitor may already have consented on a
  // previous page load before our subscriber ran.
  const current = window.cmp?.getConsent();
  if (current?.statistics) {
    // load your analytics tool
  }
});
```

#### DOM event for non-JS-API integrations

The same notification also fires as a CustomEvent on `document`:

```ts
document.addEventListener('cmp:consent-change', (event: CustomEvent) => {
  console.log(event.detail.type, event.detail.categories);
});
```

This is the path for Tag Manager–style integrations that prefer DOM
events over module imports.

## Listening for the consent moment via dataLayer

If you'd rather not depend on `window.cmp` (for example, your tag is
already running as part of GTM and just wants the GCM signal), the
`dataLayer` push pattern still works:

```ts
window.dataLayer = window.dataLayer || [];
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
