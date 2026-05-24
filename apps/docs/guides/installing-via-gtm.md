# Installing via Google Tag Manager

Most marketing-led teams want to control tags through Google Tag Manager
rather than touch the site source. cmp.js installs cleanly through GTM,
but **ordering matters** — GTM is itself just a script that fires
analytics + ads tags, and those tags must wait for our banner to set
consent first.

## The two-tag pattern

GTM has its own pre-built consent integration ("Consent Mode") that
respects what cmp.js publishes to `dataLayer`. The trick is:

1. **Tag A — cmp.js loader**: a Custom HTML tag that loads our banner
   _before_ anything else. Fires once, on every page, as early as
   possible.
2. **Existing GTM tags**: configured with consent requirements so they
   wait for cmp.js to publish the right consent state.

You don't need to disable GTM, downgrade triggers, or do any of the
fragile script-blocking gymnastics described in
[Blocking third-party scripts](./blocking-scripts) — provided your tags
use Consent Mode properly. Skip to the next section if you control GTM
configuration; the script-blocking page is for sites that embed scripts
directly in source.

## Step 1 — Add cmp.js as a Custom HTML tag

In GTM (Tags → New):

| Field              | Value                                  |
| ------------------ | -------------------------------------- |
| Tag type           | **Custom HTML**                        |
| Tag name           | `Effect CMP — cmp.js loader`           |
| HTML               | (see below)                            |
| Tag firing options | **Once per page**                      |
| Trigger            | **Consent Initialization — All Pages** |

The HTML:

```html
<script>
  (function () {
    // Belt-and-braces: deny everything until cmp.js sets the real defaults.
    // cmp.js will overwrite these synchronously, but if anything fires in
    // the millisecond before it loads, we want it consent-denied.
    window.dataLayer = window.dataLayer || [];
    function gtag() {
      dataLayer.push(arguments);
    }
    gtag('consent', 'default', {
      ad_storage: 'denied',
      analytics_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      personalization_storage: 'denied',
      functionality_storage: 'granted',
      security_storage: 'granted',
      wait_for_update: 500,
    });
  })();
</script>
<script src="https://assets.effectcmp.com/loader.js" data-domain-id="pk_REPLACE_ME" async></script>
```

Replace `pk_REPLACE_ME` with the **Public key** from your domain's
detail page in admin.

The **Consent Initialization — All Pages** trigger is GTM's built-in
trigger specifically for consent setup. It fires before any other tags
on the page, including before _Page View_. That's exactly what we need:
cmp.js loads, sets the GCM defaults, and only _then_ does GTM start
firing your other tags.

## Step 2 — Set consent requirements on your existing tags

For each tag in your GTM container that drops a cookie or sends data
to a third party, open it and configure **Consent Settings**:

| Tag type                                | Required consent                                                |
| --------------------------------------- | --------------------------------------------------------------- |
| GA4 Configuration                       | `analytics_storage`                                             |
| GA4 Event                               | `analytics_storage`                                             |
| Google Ads Conversion / Remarketing     | `ad_storage`, `ad_user_data`, `ad_personalization`              |
| Floodlight                              | `ad_storage`, `ad_user_data`, `ad_personalization`              |
| Meta / Facebook Pixel (via custom HTML) | `ad_storage`, `ad_user_data`                                    |
| LinkedIn Insight (via custom HTML)      | `ad_storage`                                                    |
| TikTok Pixel                            | `ad_storage`                                                    |
| Hotjar                                  | `analytics_storage`                                             |
| Microsoft Clarity                       | `analytics_storage`                                             |
| Intercom                                | `functionality_storage` (essential — usually no consent needed) |

When you set required consent on a tag, GTM holds it until the
matching consent type flips to `granted`. That happens the moment the
visitor clicks accept (or save preferences) in cmp.js.

## Step 3 — Map cmp.js' four categories to GCM signals

cmp.js writes seven GCM v2 signals to `dataLayer` based on the four
banner categories. Reference, in case you're configuring tags
manually:

| cmp.js category | GCM signals it controls                            |
| --------------- | -------------------------------------------------- |
| `necessary`     | (none — always granted)                            |
| `preferences`   | `personalization_storage`                          |
| `statistics`    | `analytics_storage`                                |
| `marketing`     | `ad_storage`, `ad_user_data`, `ad_personalization` |

`functionality_storage` and `security_storage` aren't tied to a visitor
category — they default to `granted` for essential session storage and
can be overridden in the banner config under **GCM defaults**.

## Verifying it works

1. Open your site with **GTM Preview** (`Preview` button in GTM).
2. Check the **Consent** tab in Tag Assistant.
3. Before clicking the banner: all signals should be `denied` (or
   whatever your configured defaults are).
4. Click **Accept all** on the cmp.js banner.
5. The Consent tab should flip to `granted` across the board.
6. GA4 / Ads tags should now fire — they were waiting on
   `analytics_storage` / `ad_storage`.

If signals don't flip, jump to [Troubleshooting →
Tracking scripts don't run after accept](./troubleshooting#tracking-scripts-dont-run-after-accept).

## What if I already have a "consent" tag in GTM?

If you've previously installed a different CMP through GTM
(CookieBot, OneTrust, etc.), remove that tag first. Two consent
managers fighting over the same `dataLayer` is a recipe for
"sometimes-consents-sometimes-doesn't" bug reports.

Also check for any **GTM Templates** in your container that wrap a
competing CMP — they go in **Templates** (left sidebar) rather than
**Tags**. Remove or pause those too.

## What if GTM itself needs to be gated?

Some operators consider the GTM container script itself a tracker
because it can be configured to fire arbitrary tracking on load. In
practice GTM is inert until you publish a tag through it — but if you
want belt-and-braces, you can wrap the GTM bootstrap with a
`<script type="text/plain" data-category="statistics">` (see
[Blocking third-party scripts](./blocking-scripts)) and let cmp.js
release it on consent.

This is a tradeoff: gating GTM at the script level means
`dataPushed` events from before consent are lost, which can affect
custom analytics. Most teams leave GTM ungated and rely on per-tag
consent requirements as configured above.

## Quick checklist

- [ ] Custom HTML tag with cmp.js + GCM defaults exists
- [ ] Tag fires on **Consent Initialization — All Pages** trigger
- [ ] Tag is configured to **fire once per page**
- [ ] `pk_REPLACE_ME` replaced with your real public key
- [ ] Every GA4 / Ads / pixel tag has a **required consent** configured
- [ ] No competing CMP tag is still active in the container
- [ ] Verified via GTM Preview that signals flip from denied → granted on accept
