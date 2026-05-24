# @tracewatch/docs

User-facing documentation for Tracewatch, built with [VitePress](https://vitepress.dev/).

Covers:

- **Implementing the banner** — embed snippet + per-stack guidance
- **Configuring your site** — CSP, cross-subdomain consent, script blocking
- **Operations** — notifications, rate limits, troubleshooting
- **Coming soon** — placeholder pages for cookie policy generation, vendor
  management, A/B testing (so the future shape is documented and the
  current absence is honest)

## Local dev

```bash
bun --filter @tracewatch/docs run dev
```

Serves at `http://localhost:5173` by default. Picked up automatically by
`bun run dev` at the workspace root (turbo fans out to it).

## Build

```bash
bun --filter @tracewatch/docs run build
```

Outputs to `apps/docs/.vitepress/dist`. Deploy via Cloudflare Pages
(target: `apps/docs/.vitepress/dist`) at `docs.tracewatch.io`.

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
