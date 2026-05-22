# Effect CMP

Effect Digital's white-label Consent Management Platform — a CookieBot/CookieYes replacement owned end-to-end.

## What's in this monorepo

```
apps/
  api/             Laravel 13 (PHP 8.3+) — admin API, scan dispatch, cookie DB
  admin/           Nuxt 4 + Tailwind 4 — admin dashboard
  banner/          Vanilla TS (Vite) — embeddable consent banner script
  edge/            Cloudflare Workers — config delivery + consent ingest

packages/
  types/           Shared TS types: BannerConfig, ConsentEvent, GcmSignals
  cookie-db-seed/  OpenCookieDatabase importer → canonical cookie schema
```

## Stack

- **Bun 1.3+** (package manager + runtime), **Turborepo** for orchestration
- **Node 22+** (Nuxt, Wrangler, Vite still run on Node)
- **PHP 8.3+**, **Composer**, **Laravel 13** (Herd locally)
- **Cloudflare**: Workers, KV, D1, R2 (Wrangler for local dev)

## Prerequisites

- Bun 1.3+ (`bun --version`) — install via `curl -fsSL https://bun.sh/install | bash`
- Node 22+ (`node -v`)
- PHP 8.3+ via [Laravel Herd](https://herd.laravel.com/)
- Composer 2.x
- Cloudflare account + `wrangler login`

## First-run setup

```bash
bun install
cd apps/api && composer install
```

## Common scripts (root)

```bash
bun run dev          # turbo run dev (all apps in parallel)
bun run build        # turbo run build
bun run check        # lint + typecheck across all packages
```

## Per-app docs

See each app's own README:
- `apps/api/README.md`
- `apps/admin/README.md`
- `apps/banner/README.md`
- `apps/edge/README.md`

## Reference

- Architecture & implementation plan: `~/.claude/plans/i-want-to-potentially-zesty-pie.md`
- Cloudflare Browser Rendering limits: https://developers.cloudflare.com/browser-rendering/platform/limits/
- OpenCookieDatabase (CC0 seed source): https://github.com/jkwakman/Open-Cookie-Database
