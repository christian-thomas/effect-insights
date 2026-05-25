# Consent management troubleshooting

Symptoms specific to the consent banner, cookie scanner, consent log,
and receipts. If you're chasing an accessibility scan failure, that's
the [accessibility troubleshooting page](./accessibility) instead.

## Banner doesn't render

A `<script>` tag is there but the banner never appears.

| Symptom                                             | Cause                                                               | Fix                                                                                                |
| --------------------------------------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Console shows `Refused to load the script…`         | Strict CSP without the cmp.js origin in `script-src`                | Add `https://assets.effectinsight.com` to `script-src` and a nonce on the embed tag. See [CSP](../csp). |
| Console shows `Refused to apply inline style…`      | Strict CSP without nonce propagated to dynamically-injected styles  | Add `<meta name="csp-nonce" content="…">` so cmp.js can pick it up. See [CSP](../csp).              |
| Console shows `connect-src…` violation              | `connect-src 'self'` blocks the config fetch                        | Allow `https://assets.effectinsight.com` in `connect-src`.                                             |
| No console errors, no banner                        | Visitor already has a valid `_effectinsight` cookie                     | This is correct behaviour. Clear the cookie in dev tools, hard-reload.                             |
| `data-domain-id` value is missing or wrong          | Worker returns 404 from `/config/:publicKey`; cmp.js silently bails | Copy the **Public key** from the domain detail page in admin — it's a `pk_` prefixed string.       |
| Banner is published but draft hasn't been published | Worker returns 404 because no published config exists in KV         | Go to the banner editor and hit **Publish**.                                                       |

The fastest way to bisect: in dev tools → Network, filter to `cmp` or
`effectinsight` and inspect the requests:

- **`loader.js`** should be `200`.
- **`cmp.<hash>.js`** should be `200`.
- **`config/<publicKey>`** should be `200` with `{ "config": {…}, "country": "…" }`.

If any of those 4xx, the embed is wrong; if any 5xx, see the
[Cloudflare outage runbook](https://github.com/effect-digital/cmp-project/blob/main/docs/runbooks/cloudflare-outage.md).

## Tracking scripts don't run after accept

Visitor clicks accept all, but the analytics / ads tags don't fire.

| Symptom                                                | Cause                                                                                          | Fix                                                                                                                                      |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Scripts have no `type="text/plain"` attribute          | They aren't blocked in the first place — they ran before consent                               | Wrap them in `<script type="text/plain" data-category="…">`. See [Blocking third-party scripts](../blocking-scripts).                     |
| Scripts have `data-category="analytics"` (wrong key)   | cmp.js only recognises four categories — `necessary`, `preferences`, `statistics`, `marketing` | Use the spelling above exactly.                                                                                                          |
| Strict CSP blocks the re-injected script               | The original blocked script had no nonce, cmp.js can't fabricate one                           | Either add the nonce to the _original_ `<script type="text/plain">` tag, or rely on the host-page meta-nonce fallback. See [CSP](../csp). |
| The visitor previously rejected statistics + marketing | Their stored consent says denied; cmp.js correctly skips unblocking                            | Either clear the `_effectinsight` cookie and reload, or publish a material change to force re-prompt.                                        |

## Cross-subdomain consent isn't sticky

Visitor accepts on `www.example.com` but sees the banner again on
`app.example.com`.

| Symptom                                                         | Cause                                                                                         | Fix                                                                                                                                                                                                          |
| --------------------------------------------------------------- | --------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Domain only has one banner config, but cross-subdomain isn't on | `behaviour.crossSubdomainConsent` defaults to off                                             | Enable it in the banner editor → Behaviour → Cross-subdomain consent. Republish. This is a [material change](../triggering-scans#material-vs-cosmetic-publishes) — all existing visitors will re-prompt once. |
| Apex domain is on the public-suffix list (`.co.uk`, `.gov.au`)  | cmp.js' trivial apex extraction won't cover `.co.uk` properly                                 | [Get in touch](mailto:support@effectdigital.com) — the trivial apex extraction is documented to be incomplete; we'll switch in `tldts` when we hit the first real case.                                      |
| Visitor is on an IP address or `localhost`                      | Browsers reject `Domain=.localhost` cookies — cmp.js detects this and falls back to host-only | This is expected in dev. Production hostnames work fine.                                                                                                                                                     |

## Unknown cookies in the review queue

Slack pings you about unknown cookies, or the **Review queue** tab on
`/cookies` shows a number.

These are cookies the scanner observed that don't match any pattern in the
canonical database OR any of your overrides. You triage by deciding what
they are:

1. Open **Cookies → Review queue** in admin.
2. For each row, click **Create override**.
3. Pick a category (Necessary / Preferences / Statistics / Marketing).
4. Optionally scope to a specific domain instead of org-wide.
5. Save. The row disappears from the queue and future scans pick up the
   classification automatically.

If you want to ignore a cookie entirely (e.g. it's a session cookie you
explicitly don't care about), categorise it as **Necessary** with a
purpose like _"Session cookie — first-party, no tracking"_. That's the
defensible position rather than dropping it on the floor.

## Cookie scan failed

A scan stays in `failed` state in `/scans`. (For accessibility scans
that won't complete, see
[Accessibility → Accessibility scan failed](./accessibility#accessibility-scan-failed).)

| Symptom                                        | Cause                                                                       | Fix                                                                                                                                                            |
| ---------------------------------------------- | --------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `errorLog.message` mentions `timeout`          | The target site took too long to respond                                    | Retry the scan. If it consistently fails, the target site might be blocking the scanner; raise with the client.                                                |
| `errorLog.message` mentions `blocked` or `403` | Site has bot protection (Cloudflare WAF, Akamai, etc.) blocking our scanner | Whitelist Cloudflare's Browser Rendering IPs on the target site. Cloudflare publishes these.                                                                   |
| `errorLog.message` mentions `quota`            | Cloudflare Browser Rendering plan limit hit                                 | Upgrade the Cloudflare account or reduce scan cadence + sitemap caps.                                                                                          |
| All scans failing at once                      | Cloudflare Browser Rendering outage                                         | Check https://www.cloudflarestatus.com — see the [outage runbook](https://github.com/effect-digital/cmp-project/blob/main/docs/runbooks/cloudflare-outage.md). |

## Consent events not showing in the admin

You triggered consent in a browser but the **Consent log** in admin shows
nothing.

| Symptom                                                                    | Cause                                                                                                                                    | Fix                                                                                                                                                                                                                     |
| -------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Network tab shows `POST /consent` returning 429                            | Rate-limit triggered                                                                                                                     | Wait a minute. cmp.js' outbox will retry on the next page load. See [Rate limits](../rate-limits).                                                                                                                       |
| Network tab shows `POST /consent` returning 5xx                            | Edge worker / D1 outage                                                                                                                  | Check the [Cloudflare outage runbook](https://github.com/effect-digital/cmp-project/blob/main/docs/runbooks/cloudflare-outage.md). The event lands in cmp.js' localStorage outbox and replays on the next page load.    |
| `POST /consent` returns 202 but admin shows nothing                        | The admin-side mirror hasn't archived the event yet; D1 has it but the nightly archive job hasn't run                                    | The D1 → admin archive runs at 03:00 UTC. Until then the event lives in D1 only. If you can't wait, ask support to trigger an out-of-band archive.                                                                      |
| Visitor's `localStorage` quota was exhausted when a `POST /consent` failed | cmp.js' outbox can't persist the failed event; it's dropped silently. Rare in practice — browsers give ~5MB and one event is ~500 bytes. | Check whether the affected domain has heavy `localStorage` usage from other code (SPA caches, offline-first apps). If you're investigating "events disappeared during a CF incident", this is one possible explanation. |
| Cross-tenant issue                                                         | You're logged into a different organisation than the one that owns the domain                                                            | Switch organisation via the dropdown in the sidebar.                                                                                                                                                                    |

## Receipt download fails

You hit **Download receipt → JSON/PDF** on a consent event and get an error.

| Symptom                            | Cause                                                          | Fix                                                                                     |
| ---------------------------------- | -------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| 401 Unauthorized                   | Session expired                                                | Log in again.                                                                           |
| 404 Not Found                      | The event you're trying to download isn't in your organisation | Check the URL — `event_id` must be one of _your_ events.                                |
| 500 with `signature` related error | `CONSENT_RECEIPT_SIGNING_KEY` not set on the API server        | Set the env var on the API server (`openssl rand -hex 32`) and restart the queue worker. |
| PDF downloads but is blank         | The PDF renderer hit an error                                  | Check the API server logs — usually a missing dependency or font issue.                 |

## Related

- [Platform troubleshooting](./platform) — passkey sign-in, auth, rate-limit symptoms.
- [Accessibility troubleshooting](./accessibility) — a11y scan failure modes.
