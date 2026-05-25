# Troubleshooting

The most common things that go wrong, what they look like, and how to fix
them. Pick the module the symptom belongs to — the pages are organised
the same way the rest of the site is, so consent and accessibility live
side by side with a thin shared platform section.

## By module

- **[Consent management](./consent)** — banner doesn't render, tracking
  scripts don't fire after accept, cross-subdomain consent isn't sticky,
  unknown cookies in the review queue, cookie scan failed, consent
  events not showing in the admin, receipt download fails.
- **[Accessibility](./accessibility)** — scan stuck queued, scan times
  out, no findings on a page you know is broken, Slack alert didn't
  fire, scheduler skipped a domain, trend chart empty, accessibility
  scan failed.
- **[Platform](./platform)** — passkey sign-in / registration, generic
  auth failures, rate-limit symptoms across both modules.

## Where to get more help

- Check the [admin app](https://admin.effectinsight.com) for the latest
  status of your domain.
- For Cloudflare-side incidents (worker down, KV degraded, Browser
  Rendering quota exhausted), see the
  [Cloudflare outage runbook](https://github.com/effect-digital/cmp-project/blob/main/docs/runbooks/cloudflare-outage.md).
- Anything else: [support@effectdigital.com](mailto:support@effectdigital.com).
