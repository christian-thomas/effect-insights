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
2. Open **Settings → Passkeys**.
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

What the server stores per passkey:

- A label the operator picked (e.g. "MacBook TouchID").
- The credential ID (a public identifier — *not* secret material).
- The public key + algorithm + counter + transports, serialised as
  JSON in the `data` column.

We do **not** store anything that could let a thief forge an
assertion. The private key never leaves the authenticator —
that's the whole point of WebAuthn. A database leak gives an
attacker public keys, which are useless without the matching
hardware.

The API never returns the `credential_id` or `data` fields. The
list endpoint exposes only the label, last-used timestamp, and the
row's own UUID (which is what the revoke endpoint accepts) —
verified by the
`list_response_does_not_leak_credential_id_or_public_key_data`
feature test.

The registration ceremony's challenge is stashed in the Laravel
session and validated server-side on the follow-up POST, so a
replay against a leaked browser response can't enrol a new key
without the matching session cookie.

## Revoking a passkey

Settings → Passkeys → ⋯ → **Revoke**. The credential is removed
immediately; the next sign-in attempt with that authenticator fails.
Existing sessions stay alive — revoking doesn't kick the operator
out, it only prevents new sessions starting from that credential.

If an operator loses their authenticator (lost laptop, broken
YubiKey), a superuser can sign in to admin and delete the row from
their behalf via the API:

```bash
# Find their passkeys
curl https://api.effectinsight.com/api/passkeys \
  -H 'Cookie: <superuser sanctum session>' \
  -X GET

# Tenant scope: a superuser logged into the user's org can list/
# delete on their behalf via the same endpoint while acting as them.
```

Or remove the row directly from the database:

```sql
DELETE FROM passkeys WHERE authenticatable_id = '<user-uuid>';
```

## REST surface

The admin SPA's auth + management endpoints. Tenant scope: the
authentication endpoints are **unauthenticated** (the visitor
hasn't logged in yet); the management endpoints require Sanctum.

```
POST   /api/login                              Email + password sign-in
POST   /api/passkeys/authentication-options    Passkey login challenge (no auth)
POST   /api/passkeys/authenticate              Passkey login verify (no auth)
GET    /api/passkeys                           List current user's passkeys (auth)
POST   /api/passkeys/register-options          Registration challenge (auth)
POST   /api/passkeys                           Register a new passkey (auth)
DELETE /api/passkeys/{passkey}                 Revoke (auth, own only)
GET    /api/me                                 Current session (auth)
POST   /api/logout                             End session (auth)
```

All bodies + responses are JSON. The two-step ceremony for
registration is:

1. `POST /api/passkeys/register-options` — server generates the
   WebAuthn `PublicKeyCredentialCreationOptions`, stashes them in
   the session, returns as JSON.
2. Browser calls `navigator.credentials.create()` with those
   options. The user's authenticator prompts.
3. `POST /api/passkeys` with `{ passkey: <browser response JSON>,
   name: "label" }` — server pulls the original challenge from the
   session and validates the response against it.

Authentication is the same shape but with `authentication-options` →
`navigator.credentials.get()` → `authenticate`.

## Why both methods?

Sometimes the operator's laptop with all the registered passkeys is
on the desk at home and they're working from a borrowed machine.
Sometimes the YubiKey is in a drawer in the office. Sometimes the
biometric sensor decides to be uncooperative. In all these cases
the email + password fallback gets them in.

Passwordless-only setups (where a passkey is the *only* way to
sign in) are great for security but operationally fragile — one
broken authenticator = one IT ticket per affected operator. The
combined approach is the pragmatic middle ground.

## Implementation reference

- Package: `spatie/laravel-passkeys` ^1.7. Picks up WebAuthn via
  `web-auth/webauthn-lib`. Surgical addition — no scaffolding
  beyond the credentials table.
- Migrations: `passkeys` table (UUID PKs to match the project rule
  — the package's stub uses `$table->id()` so we ship our own).
- Subclassed model: `App\Models\Passkey` extends Spatie's with
  `HasUuids`; configured under `config/passkeys.php`.
- User model: implements `Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys`,
  uses the `InteractsWithPasskeys` trait.
- Relying party: `app.url`'s host. Use the registrable domain (not a
  subdomain) so a passkey registered on `app.effectinsight.com`
  works across `api.` and `assets.` too.
