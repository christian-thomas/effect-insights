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

## The invitation email

The invitee receives a branded email containing:

- A magic link to accept the invitation
- The organisation name and inviter
- Expiry date (7 days from send)

If they don't receive it, check their spam folder, then verify the
address on the **Users & invitations** tab. If still missing, revoke
and re-send the invitation.

## Coming soon

- **Per-org role enforcement** — granular `owner` / `admin` / `viewer` roles
  beyond superuser. Today all invited users can do everything within their
  org after accepting.
- **Team invitations without email** — in-app invite codes for environments
  where email forwarding isn't available.
