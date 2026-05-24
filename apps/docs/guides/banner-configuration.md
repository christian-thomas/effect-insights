# Banner configuration

Reference for every field in the banner editor. Open it from
**Domains → {your domain} → Banner config** in the admin.

The editor saves a draft on every change; clicking **Publish** promotes
that draft to the live config served via Cloudflare KV. See
[Material vs cosmetic changes](#material-vs-cosmetic-changes) below
for what each field does to consented visitors when you publish.

## Layout

| Layout                   | What it looks like                                                   |
| ------------------------ | -------------------------------------------------------------------- |
| `modal`                  | Centred dialog with a backdrop. Most prominent — best consent rates. |
| `bar-top` / `bar-bottom` | Edge-pinned bar across the viewport. Less intrusive than a modal.    |
| `corner`                 | Compact card pinned bottom-right. Most subtle.                       |

Changing layout is **cosmetic** — visitors keep their existing consent.

## Locales

Each locale you enable needs a complete copy block (title, body,
buttons, category descriptions). The banner picks the locale based on
`localeDefault`; runtime negotiation against the visitor's
`Accept-Language` header isn't shipped yet — see
[Coming soon — locale negotiation](#coming-soon).

If you add a new locale, the banner re-prompts every consented visitor
(it's material — the visitor sees a different document than they
consented to).

## Colours + copy

Six hex colours: `background`, `text`, `primary`, `primaryText`,
`secondary`, `secondaryText`. The banner uses these directly — no
theme system, no derived shades. Pick from your brand palette.

Per-locale copy fields:

- `title` (max 120 chars)
- `body` (max 600)
- `acceptAllLabel`, `rejectAllLabel`, `customiseLabel`, `savePreferencesLabel` (max 60 each)
- `categoryDescriptions.{necessary,preferences,statistics,marketing}` (max 300 each)

Colour + copy changes are **cosmetic** — visitors keep their consent.

## GCM defaults

These are the seven Google Consent Mode v2 signals cmp.js pushes to
`dataLayer` _before_ anything else loads. Set the default state for
each (`granted` or `denied`). On visitor consent, cmp.js updates the
right signals according to which categories were accepted.

| Signal                    | What it gates                                                                           |
| ------------------------- | --------------------------------------------------------------------------------------- |
| `analytics_storage`       | GA4, Hotjar, etc. (driven by `statistics` category)                                     |
| `ad_storage`              | Google Ads, Meta pixel, etc. (driven by `marketing`)                                    |
| `ad_user_data`            | Sharing event data with Google Ads (driven by `marketing`)                              |
| `ad_personalization`      | Personalised ad targeting (driven by `marketing`)                                       |
| `personalization_storage` | Site-wide personalisation (driven by `preferences`)                                     |
| `functionality_storage`   | Essential session/preference storage (not driven by a category — set this to `granted`) |
| `security_storage`        | Auth + anti-fraud cookies (not driven — set to `granted`)                               |

GCM default changes are **material** — visitors re-prompt because
they're consenting under a different baseline.

## Geo rules

Decide which countries see the banner. The edge worker reads
`cf-ipcountry` from each config request and applies the rule.

| Mode         | What it does                                                                          |
| ------------ | ------------------------------------------------------------------------------------- |
| `always`     | Banner shows everywhere. The simplest, least-risky choice for GDPR-aware operators.   |
| `eu_uk_only` | Banner shows in the EU + UK; everywhere else gets no banner and defaults stay denied. |
| `whitelist`  | Banner shows only in the countries listed in `countries`.                             |
| `blacklist`  | Banner shows everywhere except the countries listed in `countries`.                   |

The `countries` array uses ISO 3166-1 alpha-2 codes (`GB`, `DE`, `FR`,
etc.).

::: warning Privacy law isn't just GDPR
"Banner shows nowhere except the EU" is tempting but wrong for most
clients. GDPR-comparable laws apply in the UK, EEA, Switzerland,
California (CCPA/CPRA), Brazil (LGPD), Canada (PIPEDA), Australia, and
counting. If in doubt, leave it on `always` and accept the conversion
hit.
:::

Geo rule changes are **material** — different visitors see (or stop
seeing) the banner under the new config, which changes what they're
consenting to.

## Behaviour

### `rePromptAfterDays`

Integer between 1 and 1825 (default 365). How long the consent cookie
lives before cmp.js asks again. ICO guidance suggests 12 months max;
some operators use 90 days.

Changing this is **cosmetic** — only affects cookie lifetime, not
what the visitor is consenting to. Existing cookies keep their
original max-age; the new value applies to consents recorded after
the publish.

### `honourGpc`

Boolean (default `false` — but the admin pre-fills it `true` on new
domains). When `true`, cmp.js reads
`navigator.globalPrivacyControl`. If the visitor's browser is sending
the signal (Brave, DuckDuckGo, Firefox with `privacy.gpc.enabled`)
and they have no stored consent yet, cmp.js auto-records a `denied`
event, sets the cookie, and skips rendering the banner. The visitor
never sees the dialog and their refusal is logged.

If they already have stored consent (positive or negative), that
choice wins — GPC doesn't override.

GPC compliance is required under the CCPA/CPRA in California. For
most clients, turning this on is the right move.

Changing this is **material** — the population of visitors who see
the banner shifts, so existing consent is invalidated.

### `autoDenyOnDismiss`

Boolean (default `false`). When `true`, pressing Escape on the dialog
or clicking the backdrop outside the card records a `denied` event
and closes the banner. When `false`, both are no-ops — the visitor
must make an explicit choice via the buttons.

The default is `false` because most interpretations of GDPR consider
dismissal-as-denial an implicit-denial foot-gun: the visitor didn't
actively refuse, they just looked away. Some legal teams want the
opposite — explicit option to refuse without engaging — and turn it
on. Talk to your legal advisor before flipping it.

Changing this is **material** — different visitors will record
denials they wouldn't have under the previous config.

### `crossSubdomainConsent`

Boolean (default `false`). When `true`, cmp.js writes the consent
cookie with `Domain=.{apex}` so subdomains of the apex share it. See
[Cross-subdomain consent](./cross-subdomain-consent) for the
mechanics + the public-suffix-list caveat.

Changing this is **material**.

### `granularity`

`categorised` (default) or `basic`. Categorised is the four-category
flow: Accept-all + Reject-all + Customise button that opens a per-
category pane (necessary / preferences / statistics / marketing) +
Save-preferences. Basic collapses to a binary Accept-all / Reject-all
with no customise affordance.

Pick basic when the site has only essential cookies + one analytics
tool — the per-category UI is friction for a meaningful choice the
visitor isn't really making. Pick categorised for anything more complex
(or when uncertain) — it matches GDPR Article 7's "as specific as
informed consent" bar.

Changing this is **cosmetic**. The stored consent cookie shape is
identical between the two modes (it's always four booleans), so
flipping doesn't invalidate existing consent. A visitor who accepted
all categories under categorised mode still has the same stored
consent under basic; they just won't see a customise UI on the next
re-prompt window.

## Coming soon

- **Locale negotiation** — pick the visitor's preferred locale from
  `Accept-Language` rather than always falling back to `localeDefault`.
  Today, `localeDefault` is what every visitor sees regardless of
  browser language.

## Material vs cosmetic changes

This is the most important section in this page if you're publishing
regularly.

**Material** — visitors are re-prompted on their next visit:

- `gcm_defaults.*` — any of the seven signals
- `geo_rules.mode` or `geo_rules.countries`
- `behaviour.honourGpc`
- `behaviour.autoDenyOnDismiss`
- `behaviour.crossSubdomainConsent`
- `locales_enabled` — adding or removing a locale

**Cosmetic** — visitors keep their existing consent:

- `layout`
- `granularity` — switching between `basic` and `categorised`
- `locale_default` (picking a different default among the enabled set)
- `colors.*`
- `copy.*` (wording changes — title, body, button labels, category
  descriptions)
- `behaviour.rePromptAfterDays`

The admin's **Pending changes** panel (above the publish button) shows
exactly which fields you've changed since the last publish and tags
each as material or cosmetic. The button label changes to **Publish
(re-prompts visitors)** when any material change is staged so you
can't miss it.
