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

## Scan failures

When a scan transitions to `failed` (the scanner worker timed out, threw,
or returned a malformed response) a Slack message lands in your
configured channel:

> _Scan failed on `app.example.com` (Acme Ltd)_:
>
> `RuntimeException`
>
> ```
> Browser binding timeout
> ```
>
> [Open scan in admin →](https://admin.effectcmp.com/scans/uuid-here)

The alert fires regardless of how the scan was triggered (manual,
scheduled, or API). The scan row stays at `failed` with the original
exception in `error_log` even if the alerter itself errors — alert
delivery is best-effort and never overwrites the canonical record.

## Accessibility violations

When an accessibility scan completes with violations at or above the
configured impact threshold, a Slack message lands in your channel:

> _2 accessibility violations on `app.example.com` (Acme Ltd)_:
>
> Critical 1 · Serious 1 · Moderate 0 · Minor 0
>
> [Open scan in admin →](https://admin.effectcmp.com/accessibility-scans/uuid-here)

The default threshold is **`serious`** — Critical or Serious violations
alert; Moderate or Minor stay quiet. Override per-deploy via
`ACCESSIBILITY_ALERT_THRESHOLD` (one of `critical | serious | moderate
| minor`) in `apps/api/.env`.

Failed accessibility scans also alert separately via the
`AccessibilityScanFailed` notification — same block-kit shape as the
cookie scanner's `ScanFailed`, just with a link to the accessibility
scan detail page. During a Cloudflare Browser Rendering outage you'll
see one Slack message per failed scan; **expected, ignore** — the
alerts stop on their own once Browser Rendering recovers.

See [Accessibility scans](./accessibility-scans) for the full setup.

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

The banner fires a real-user-monitoring beacon to the edge `/rum`
endpoint on every render with paint-time + browser context. This is
**not a notification** and there's no dashboard yet — each beacon
becomes a single structured JSON log line emitted by the consent
worker, which can be queried via:

- `wrangler tail effect-cmp-consent --format=json | grep cmp_rum` for
  live tailing during incidents.
- Cloudflare Logpush → your data warehouse for aggregated analysis.

The beacon shape:

```json
{
  "type": "cmp_rum",
  "domainPublicKey": "pk_…",
  "paintMs": 23,
  "hadStoredConsent": false,
  "effectiveConnectionType": "4g",
  "deviceMemoryGb": 8
}
```

`hadStoredConsent: true` means cmp.js short-circuited via an existing
valid cookie (the fast path, typically <10ms). `false` means it rendered
the banner from a cold start. Track both p95s separately when you
build the dashboard.

A first-class RUM dashboard is on the roadmap. Until it lands, the
logs are the source of truth.

## Coming soon

- **Per-org Slack channels** — route an org's alerts to a tenant-specific
  channel.
- **Email notifications** — same alerts via email for orgs without Slack.
- **Banner-publish notifications** — heads-up to ops when a material
  change goes live.
