# Personal account settings

Every operator has a personal account separate from organisation membership.
Manage your profile, password, and passkeys from **Profile** in the admin
sidebar.

## Your profile

Update your name and email. The email is your sign-in identifier — changing it
affects password resets and invitation emails.

Open **Profile** → **Account** to edit.

## Password

Set a new password from **Profile** → **Password**. You'll need to provide your
current password to set a new one.

Changing your password does **not** sign you out of existing sessions — you
stay logged in on all devices. Sign out manually from any device if you want to
invalidate other browsers.

## Passkeys

Register, label, and revoke WebAuthn credentials (TouchID, Windows Hello,
YubiKey, etc.) from **Profile** → **Passkeys**. See the
[Authentication guide](./auth#registering-a-passkey) for the full reference.

## REST surface

The endpoints below are authenticated (require Sanctum session) and scoped to the
current user — you can only edit your own profile.

```
GET    /api/me                           Current session + user details
PATCH  /api/me                           Update name + email
POST   /api/me/password                  Change password
```

### Updating your profile

```bash
curl -X PATCH https://api.effectinsight.com/api/me \
  -H "Content-Type: application/json" \
  -H "Cookie: <sanctum-session>" \
  -d '{
    "name": "Jane Doe",
    "email": "jane.doe@example.com"
  }'
```

Both fields are optional. Returns the updated user resource.

### Changing your password

```bash
curl -X POST https://api.effectinsight.com/api/me/password \
  -H "Content-Type: application/json" \
  -H "Cookie: <sanctum-session>" \
  -d '{
    "current_password": "oldpassword123",
    "password": "newpassword456",
    "password_confirmation": "newpassword456"
  }'
```

Returns 204 No Content on success. The new password takes effect immediately for
future logins.
