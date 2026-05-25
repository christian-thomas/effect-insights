# Authentication

Operators sign in to the admin SPA via either:

- **Email + password** — the default. Set on user creation.
- **Passkeys** (WebAuthn / FIDO2) — passwordless, register one or
  more per user. TouchID, Windows Hello, YubiKey, Android biometric,
  iCloud Keychain — anything WebAuthn-compatible.

Both methods are always available. Passkeys don't replace the
password — they sit alongside it as a faster + more secure path,
and the password remains the break-glass recovery option.

## Registering a passkey

1. Sign in to the admin with your existing email + password.
2. Open **Profile → Passkeys** (click your name in the top-right, then Profile).
3. Click **Register a passkey**.
4. Give it a label (e.g. "MacBook TouchID", "YubiKey blue").
5. The browser prompts your authenticator. Approve.

The credential is bound to the platform's hostname (the registrable
domain — `effectinsight.com` in production), so a passkey
registered on `app.effectinsight.com` works across every subdomain
under it. Register multiple — each acts as a fallback for the
others.

## Signing in with a passkey

From the admin login page, click **Sign in with a passkey**. The
browser prompts your authenticator. On success the session is
established the same way as a password login — the active
organisation is pre-seeded so the next request already has tenant
context.

If the browser doesn't support WebAuthn (older versions, some
embedded webviews), the button is hidden and only the password
form shows.

## Requirements

- **HTTPS in production.** WebAuthn refuses to run over plain HTTP.
  Localhost is the only exception — `http://localhost:3001` works
  for dev, anything else (including a LAN IP or a custom dev
  hostname not pointing at loopback) needs TLS.
- **Modern browser.** Safari 16+, Chrome 108+, Firefox 119+, Edge
  108+. Older browsers don't expose `navigator.credentials.create`
  with the conditional-UI features the package relies on. iOS and
  Android Chrome / Safari pick up the platform authenticator
  automatically (Face ID, fingerprint, Android biometric).
- **One registrable domain.** A passkey registered against
  `app.effectinsight.com` is bound to `effectinsight.com` and works
  across every subdomain. Moving the admin to a different
  registrable domain (e.g. a custom client subdomain on a different
  apex) means every operator has to re-register.

## Security posture

What the platform stores per passkey:

- A label the operator picked (e.g. "MacBook TouchID").
- The credential ID — a public identifier, not secret material.
- The credential's public key and associated metadata.

We do **not** store anything that could let a thief forge an
assertion. The private key never leaves the authenticator —
that's the whole point of WebAuthn. A database leak gives an
attacker public keys, which are useless without the matching
hardware.

The admin only ever exposes the label and last-used timestamp on the
passkey list — credential identifiers and public-key material stay
server-side.

Registration challenges are held server-side and validated on the
follow-up step, so a replay against a leaked browser response can't
enrol a new key without the matching session cookie.

## Revoking a passkey

Profile → Passkeys → ⋯ → **Revoke**. The credential is removed
immediately; the next sign-in attempt with that authenticator fails.
Existing sessions stay alive — revoking doesn't kick the operator
out, it only prevents new sessions starting from that credential.

If an operator loses every authenticator they registered (lost
laptop, broken YubiKey, all in the same wallet), they can still sign
in with their email + password and revoke + re-register from
**Profile → Passkeys**. If they don't remember their password
either, contact [support@effectdigital.com](mailto:support@effectdigital.com)
— a platform engineer can clear their credentials out-of-band so
they can recover via password reset.

## Why both methods?

Sometimes the operator's laptop with all the registered passkeys is
on the desk at home and they're working from a borrowed machine.
Sometimes the YubiKey is in a drawer in the office. Sometimes the
biometric sensor decides to be uncooperative. In all these cases
the email + password fallback gets them in.

Passwordless-only setups (where a passkey is the _only_ way to
sign in) are great for security but operationally fragile — one
broken authenticator = one IT ticket per affected operator. The
combined approach is the pragmatic middle ground.
