# Notifications

Effect Insight can ping Slack — and optionally email — when something
needs an operator's attention.

## Unknown cookies found

After every completed scan, the platform checks for findings the
resolver couldn't categorise (no canonical match, no override). If any
exist, a single Slack message lands in your configured channel:

> _3 unknown cookies_ found on `app.example.com` (Acme Ltd):
>
> • `_ga2`
> • `_fb_xyz`
> • `foo_bar`
>
> [Open the review queue →](https://admin.effectinsight.com/cookies?tab=review)

Clicking the link drops you on the Cookie database admin page with the
**Review queue** tab pre-selected — see
[Troubleshooting → Unknown cookies](./troubleshooting/consent#unknown-cookies-in-the-review-queue)
for how to triage from there.

## Cookie scan failures

When a cookie scan transitions to `failed` (the scanner timed out,
threw, or returned a malformed response) a Slack message lands in your
configured channel:

> _Scan failed on `app.example.com` (Acme Ltd)_:
>
> `RuntimeException`
>
> ```
> Browser binding timeout
> ```
>
> [Open scan in admin →](https://admin.effectinsight.com/scans/uuid-here)

The alert fires regardless of how the scan was triggered (manual,
scheduled, or external). The scan row stays at `failed` with the
original exception captured even if alert delivery itself errors —
alerts are best-effort and never overwrite the canonical record.

## Accessibility violations

When an accessibility scan completes with violations at or above the
configured impact threshold, a Slack message lands in your channel:

> _2 accessibility violations on `app.example.com` (Acme Ltd)_:
>
> Critical 1 · Serious 1 · Moderate 0 · Minor 0
>
> [Open scan in admin →](https://admin.effectinsight.com/accessibility-scans/uuid-here)

The default threshold is **`serious`** — Critical or Serious violations
alert; Moderate or Minor stay quiet. Override per-organisation from the
organisation slideover (superuser-only) — see
[Accessibility scans → Alert threshold](./accessibility-scans#alert-threshold).

Failed accessibility scans alert separately — same look, just with a
link to the accessibility scan detail page. During a Cloudflare Browser
Rendering outage you'll see one Slack message per failed scan;
**expected, ignore** — the alerts stop on their own once Browser
Rendering recovers.

See [Accessibility scans](./accessibility-scans) for the full setup.

## Email recipients

Each organisation has a **Notification email** field (comma-separated)
on its slideover. When set, every one of the four alert types (unknown
cookies, cookie scan failures, accessibility violations, accessibility
scan failures) delivers to:

- The shared Slack channel (if configured), AND
- Each address in **Notification email** (if set).

Either channel can be configured independently — Slack-only, email-
only, both, or neither (silent).

Set via the organisation slideover (**Organisations → edit →
Notification email** — superuser-only). Each address is validated;
malformed entries are silently dropped on read. To confirm delivery,
trigger a fresh accessibility scan on a domain with known violations
and check both Slack and the configured inbox.

## Configuring Slack

Slack notifications are configured platform-wide — a single workspace
and channel handle alerts for every organisation today. If your
organisation needs Slack alerts wired up, ask support — it's a one-time
platform-side setup, not something an operator flips in admin.

If Slack is unconfigured for the platform, alerts simply don't fire to
Slack. Email recipients still work independently.

## Per-org channels (not yet)

Today the Slack channel is global — every organisation's alerts land in
the same place. Per-organisation channel routing is on the roadmap; for
now the alert message includes the organisation name so you can tell
whose site triggered it.

## Real-user monitoring

The banner emits a performance beacon on every render so we can track
first-paint speed in the wild. A first-class operator dashboard for this
data is on the roadmap; until it ships, ask support if you need a
snapshot for a specific domain.

## Coming soon

- **Per-org Slack channels** — route an organisation's alerts to a
  tenant-specific channel.
- **Banner-publish notifications** — heads-up to ops when a material
  change goes live.
- **RUM dashboard** — first-paint and short-circuit-rate visualised
  per-domain in admin.
