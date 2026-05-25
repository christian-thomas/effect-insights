# @effectinsight/docs

User-facing documentation for Effect Insight, built with [VitePress](https://vitepress.dev/).

The site is organised around the platform's modules — readers can come for
one and ignore the others:

- **Platform** — managing domains, organisations (multi-tenant), authentication
- **Consent management** — banner implementation, GTM install, scanner,
  cookie policy generation, consent records + receipts, script blocking,
  cross-subdomain consent, CSP, JavaScript API surface
- **Accessibility scanning** — scheduled axe-core audits, impact thresholds,
  per-rule muting
- **Operations** — notifications, rate limits, troubleshooting (shared
  across modules)
- **Coming soon** — placeholder pages for unshipped features (vendor /
  sub-processor management, banner A/B testing)

Codebase-internal documentation (class names, source paths, package
internals) lives in the per-app READMEs (`apps/api/README.md`,
`apps/admin/README.md`, etc.) — VitePress is operator-facing only.

## Local dev

```bash
bun --filter @effectinsight/docs run dev
```

Serves at `http://localhost:5173` by default. Picked up automatically by
`bun run dev` at the workspace root (turbo fans out to it).

## Build

```bash
bun --filter @effectinsight/docs run build
```

Outputs to `apps/docs/.vitepress/dist`. Deploy via Cloudflare Pages
(target: `apps/docs/.vitepress/dist`) at `docs.effectinsight.com`.

## Editing

All content lives under `apps/docs/` as `.md` files (VitePress flavour of
markdown). The sidebar + nav are configured in
`apps/docs/.vitepress/config.ts` — keep it in sync when you add or rename
a page.

## Conventions

- Pages live under `guides/` and use kebab-case filenames.
- Placeholder pages for unshipped features stay listed in the sidebar
  under **Coming soon** with a `::: warning Not shipped yet :::` admonition
  at the top.
- Cross-link liberally — readers tend to land mid-flow on troubleshooting.
- Code blocks should reflect actual production snippets — operators copy-
  paste from here.
