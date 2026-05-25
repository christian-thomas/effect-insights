# User management

Add operators to your organisation via email invitations. Invites are 7-day magic links that let new users set their password and optionally register a passkey on first sign-in.

## Inviting a user

1. In the admin, open **Organisations** (superuser-only).
2. Click the pencil icon on your organisation to edit it.
3. Click the **Users & invitations** tab.
4. Enter an email address and click **Send invitation**.

An email lands in the invitee's inbox with a magic link. They have 7 days to
accept it.

## Accepting an invitation

When a new operator receives the email:

1. Click the invitation link (valid for 7 days).
2. Create a password.
3. Optionally register a passkey (touch ID, Windows Hello, etc.) — they can
   skip this and add one later from Profile → Passkeys.
4. Accept the terms and create their account.

On success, they're logged in and can start working. The invitation is
consumed and can't be reused.

If the link expires or they lose the email, the superuser can revoke the old
invitation and send a new one from the same **Users & invitations** tab.

## Managing team members

Once a user is added, view + remove them from the same **Users & invitations** tab.

Revoking an existing member doesn't log them out immediately — their current
session stays alive until they refresh the page or log out manually. The next
login attempt will fail (they're no longer a member of the org).

## REST surface

All user-management endpoints are **superuser-only**. Invite + accept flows
mirror the passkey ceremonies — a two-step process to prevent confusion.

```
POST   /api/organisations/{org}/invitations                 Create an invitation
GET    /api/organisations/{org}/invitations                 List pending invitations
DELETE /api/organisations/{org}/invitations/{invitation}    Revoke an unaccepted invitation

GET    /api/organisations/{org}/users                       List active members
DELETE /api/organisations/{org}/users/{user}                Remove a member

GET    /api/invitations/{token}                              Show invitation (no auth)
POST   /api/invitations/{token}/accept                       Accept invitation (no auth)
```

### Creating an invitation

```bash
curl -X POST https://api.effectinsight.com/api/organisations/<uuid>/invitations \
  -H "Content-Type: application/json" \
  -H "Cookie: <superuser sanctum session>" \
  -d '{
    "email": "jane@example.com"
  }'
```

Returns the created `Invitation` resource with a `token` and `expiresAt` timestamp.

### Accepting an invitation

Two-step flow:

1. `GET /api/invitations/{token}` — retrieve the invitation details (email + org name).
2. `POST /api/invitations/{token}/accept` with `{ password, name }` — creates the user + session.

```bash
curl -X POST https://api.effectinsight.com/api/invitations/<token>/accept \
  -H "Content-Type: application/json" \
  -d '{
    "password": "newpassword123",
    "name": "Jane Doe"
  }'
```

Returns the created user + a Sanctum session cookie.

### Listing members

```bash
curl -X GET https://api.effectinsight.com/api/organisations/<uuid>/users \
  -H "Cookie: <superuser sanctum session>"
```

Returns a paginated list of active members.

### Removing a member

```bash
curl -X DELETE https://api.effectinsight.com/api/organisations/<uuid>/users/<user-uuid> \
  -H "Cookie: <superuser sanctum session>"
```

Returns 204 No Content on success.

## Invitation email

The `UserInvited` notification sends a branded email with:

- The invitee's name (if you entered it)
- A link to `/invitations/{token}` on the admin
- Expiry date (7 days from send)
- Org name + superuser contact

The email is sent via the same mail driver as other notifications
(Cloudflare Email Routing by default — see [Notifications →
Mail driver](./notifications#mail-driver)).

## Coming soon

- **Per-org role enforcement** — granular `owner` / `admin` / `viewer` roles
  beyond superuser. Today all invited users can do everything within their
  org after accepting.
- **Team invitations without email** — in-app invite codes for environments
  where email forwarding isn't available.
