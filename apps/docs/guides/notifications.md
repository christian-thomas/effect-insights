# Notifications

Effect CMP can ping Slack when something needs an operator's attention.
Today there's one notification; more land here as we add them.

## Unknown cookies found

After every completed scan, the platform checks for findings the resolver
couldn't categorise (no canonical match, no override). If any exist, a
single Slack message lands in your configured channel:

> _3 unknown cookies_ found on `app.example.com` (Acme Ltd):
>
> • `_ga2`
> • `_fb_xyz`
> • `foo_bar`
>
> [Open the review queue →](https://admin.effectcmp.com/cookies?tab=review)

Clicking the link drops you on the Cookie database admin page with the
**Review queue** tab pre-selected — see [Troubleshooting →
Unknown cookies](./troubleshooting#unknown-cookies-in-the-review-queue) for
how to triage from there.

## Configuration

Slack notifications are configured platform-wide via two environment
variables on the Laravel API:

| Variable                         | What it does                                             |
| -------------------------------- | -------------------------------------------------------- |
| `SLACK_BOT_USER_OAUTH_TOKEN`     | Bot user OAuth token (`xoxb-…`) for your Slack workspace |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | Channel name (e.g. `#cmp-ops`) or channel id             |

To get the token:

1. Create a Slack app at https://api.slack.com/apps → **From scratch**.
2. Add the **chat:write** + **chat:write.public** OAuth scopes under
   _OAuth & Permissions_.
3. Install the app to your workspace; copy the **Bot User OAuth Token**.
4. Invite the bot to the channel: `/invite @{your-bot}`.
5. Set the two env vars on the API server (in `apps/api/.env`) and restart
   the queue worker (`php artisan horizon:terminate`).

If either env var is empty, the alert simply doesn't fire — local dev and
CI stay quiet by default.

## Per-org channels (not yet)

Today the channel is global — every org's alerts land in the same place.
Per-organisation channel routing is on the roadmap; for now the alert
message includes the org name so you can tell whose site triggered it.

## RUM telemetry

The banner also fires a real-user-monitoring beacon to the edge `/rum`
endpoint with paint-time + browser context. This isn't a notification —
it's a structured log line captured by `wrangler tail` + Cloudflare
Logpush so we can track p95 banner-paint against the <50ms budget. No
operator action needed.

## Coming soon

- **Per-org Slack channels** — route an org's alerts to a tenant-specific
  channel.
- **Email notifications** — same alerts via email for orgs without Slack.
- **Banner-publish notifications** — heads-up to ops when a material
  change goes live.
- **Scan-failure notifications** — currently a failed scan is only visible
  in the admin; alerting on it would let ops investigate without polling.
