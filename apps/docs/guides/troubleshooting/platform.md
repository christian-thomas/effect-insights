# Platform troubleshooting

Symptoms that aren't specific to either module — admin sign-in, passkey
ceremonies, generic rate-limit behaviour. Thin on purpose: most failure
modes belong to the per-module pages.

## Passkey sign-in or registration fails

You hit **Sign in with a passkey** (or **Register a passkey**) and the
flow doesn't complete.

| Symptom                                                                                  | Cause                                                                                                                                                               | Fix                                                                                                                                                                                                                                                                  |
| ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Sign in with a passkey** button isn't visible at all                                   | Browser doesn't expose `navigator.credentials` — older browser, an embedded webview, or a non-HTTPS origin that isn't `localhost`                                   | Use Safari 16+ / Chrome 108+ / Firefox 119+ / Edge 108+. In production the admin must be served over HTTPS — WebAuthn refuses to run otherwise (localhost is exempt).                                                                                                |
| Browser shows `NotAllowedError` immediately                                              | Operator cancelled the system prompt, or the authenticator timed out                                                                                                | This is treated as a quiet cancellation — no toast, no error. Click the button again and complete the biometric / hardware prompt before the system timeout (usually ~60s).                                                                                          |
| Browser shows `SecurityError: The relying party ID is not a registrable domain suffix`   | The admin SPA hostname doesn't match the registrable domain configured on the API side                                                                              | Confirm `APP_URL` on the API server matches the production admin hostname. Passkeys are bound to the registrable domain (`effectinsight.com`), so moving the admin to a different apex requires every operator to re-enrol.                                          |
| Sign-in succeeds in the browser prompt but `POST /api/passkeys/authenticate` returns 400 | Session challenge expired (operator paused mid-prompt) or the credential ID isn't recognised — passkey was revoked, or registered against a different relying party | Click **Sign in with a passkey** again to issue a fresh challenge. If it still fails, register a fresh passkey from Profile → Passkeys. The old credential is probably orphaned (e.g. an admin restore wiped the row).                                               |
| Registration completes but the new row never appears in **Profile → Passkeys**           | `POST /api/passkeys` returned 400 — `passkey-register-options` wasn't called first, or the session cookie wasn't preserved                                          | Reload the page, then click **Register a passkey** again. The two-step ceremony (`register-options` → `POST /api/passkeys`) shares the session, so anything that drops cookies between the two halves (third-party cookie blockers, incognito mode) breaks the flow. |
| Login works on one device but not another                                                | Passkey was registered on a non-syncing authenticator (e.g. a YubiKey) and that device doesn't have it                                                              | Register a second passkey on each device that needs to sign in. iCloud Keychain / Google Password Manager passkeys sync across the user's devices automatically; hardware keys + Windows Hello don't.                                                                |

If a YubiKey or laptop with all the operator's passkeys is lost, see
the "Revoking a passkey" section of the
[Authentication guide](../auth#revoking-a-passkey) for the
operator-level removal procedure.

## Generic admin sign-in fails

You can't sign in to admin at all — passkey or password.

| Symptom                                 | Cause                                                                             | Fix                                                                                                                                       |
| --------------------------------------- | --------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Password sign-in returns 419            | The CSRF / session cookie isn't being sent — usually a third-party cookie blocker | Allow cookies for `admin.effectinsight.com` and `api.effectinsight.com`. Reload and try again.                                            |
| Sign-in succeeds but the dashboard 401s | Session cookie was set on the wrong domain (cross-site context)                   | Confirm you're hitting the production admin URL, not a preview deploy on a different host. Sessions don't carry across hosts.             |
| "Too many login attempts" message       | Login throttle tripped                                                            | Wait the cooldown (usually a few minutes) and try again. If you're confident the credentials are right, use the magic-link route instead. |
| Magic-link email doesn't arrive         | Outbound mail outage, or the address isn't on the operator list                   | Check spam. If still missing, escalate to [support@effectdigital.com](mailto:support@effectdigital.com).                                  |

## Rate-limit symptoms

Both `POST /consent` and `POST /rum` are rate-limited per visitor IP.
The full behaviour (limits, what visitors experience, how to widen for
shared NATs) is documented at [Rate limits](../rate-limits) — this
section is just a pointer for common admin-side symptoms.

| Symptom                                                   | Where to look                                                                                                                |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| Consent events from one IP suddenly stop appearing        | Almost certainly a 429 — see [Consent → Consent events not showing](./consent#consent-events-not-showing-in-the-admin).      |
| Slack alert says "elevated 429s on /consent"              | Bot or scraper hammering one of your client sites. See [Rate limits → When you'd hit one](../rate-limits#when-youd-hit-one). |
| Legitimate corporate visitors all hitting 429 from one IP | Large shared NAT. [Get in touch](mailto:support@effectdigital.com) — we can widen the limit for the range.                   |

## Related

- [Consent troubleshooting](./consent) — banner, scripts, consent log, receipts.
- [Accessibility troubleshooting](./accessibility) — a11y scan failure modes.
- [Authentication](../auth) — full passkey + password reference.
- [Rate limits](../rate-limits) — full rate-limit reference.
