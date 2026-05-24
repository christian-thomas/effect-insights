# Cross-subdomain consent

Many sites span multiple subdomains — `www.example.com`,
`app.example.com`, `docs.example.com`. By default, consent on one doesn't
carry over to the others, because the `_tracewatch` cookie is scoped to
the host that wrote it.

Cross-subdomain consent makes the cookie apex-scoped so every subdomain
of the apex shares it.

## Turning it on

1. Open the banner editor: **Domains → {your domain} → Banner config**.
2. Scroll to **Behaviour**.
3. Toggle **Cross-subdomain consent** on.
4. Save the draft, then **Publish**.

::: warning Material change
Flipping this setting is a [material change](./triggering-scans#material-vs-cosmetic-publishes).
Every consented visitor on your site will see the banner again on their
next visit — they're being asked to consent under different terms (one
cookie covers a different set of hosts).

The admin's **Pending changes** panel will flag this and the publish
button will swap to "Publish (re-prompts visitors)" so you can't miss it.
:::

## What changes

- **Before**: cookie set as `_tracewatch=…; Domain=www.example.com; Path=/`.
  Only `www.example.com` reads it.
- **After**: cookie set as `_tracewatch=…; Domain=.example.com; Path=/`.
  Every host ending in `.example.com` reads it.

## Caveats

### Apex extraction is naïve

cmp.js extracts the apex client-side by taking the last two dot-separated
segments of the hostname (mirrors how the Laravel side does it). That's
fine for `example.com`, `app.example.com`, `staging.example.com` — they
all resolve to `example.com`.

It's **wrong** for public-suffix sites:

- `example.co.uk` → cmp.js extracts `co.uk` → browser refuses to set a
  cookie on `Domain=.co.uk` (good — that would be a security issue) →
  no cookie is written → banner re-prompts every page.
- `example.gov.au` → same problem.

If you're operating on a public-suffix domain, [get in touch](mailto:support@effectdigital.com).
We'll switch in the `tldts` library to handle this properly when we hit
the first real case; until then, leave cross-subdomain consent off for
these domains and the standard host-only cookie works fine.

### Apex must be on Cloudflare DNS

Browsers reject `Domain=.example.com` cookies set from a host they
consider "not the same site" by Public Suffix List rules. If your apex
isn't a real DNS-resolvable hostname (e.g. you're testing on
`localhost` or an IP), cmp.js detects this and silently omits the
Domain attribute. In production this is a non-issue.

### Switching it off again

If you publish another material change that turns cross-subdomain off,
old apex-scoped cookies from the previous publish stay in visitors'
browsers and continue to inherit. The visitor consents under the new
terms once via the re-prompt, the new cookie is host-scoped, but the
old apex cookie hangs around until it expires (12 months by default).

This is fine in practice — the cookie still records consent, just
shared more widely than the current config wants. Visitor preference
is honoured either way.
