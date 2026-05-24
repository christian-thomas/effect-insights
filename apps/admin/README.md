# @effect-cmp/admin

The operator-facing SPA — Nuxt 4 + Pinia + Nuxt UI v4. Sanctum SPA
auth against the Laravel API, white-label organisation switching, and
the full CRUD surface for domains, banner configs, scans, the cookie
database, consent log, and orgs.

## Stack

- **Nuxt 4** with `ssr: false` — authenticated SPA, no SEO. SSR is
  deliberately off: Sanctum session cookies live in the browser, and
  the Pinia stores hold `Error` instances that would devalue-crash if
  serialised. Don't try to flip SSR on without a plan for both.
- **Pinia** is the single source of truth. Every resource has a
  store (`app/stores/`) that wraps `$api` calls. **No `useFetch`** —
  composables are reserved for pure helpers.
- **Nuxt UI v4** for components. Slideovers, tables, forms, toasts.
- **oxlint + oxfmt** instead of ESLint/Prettier (faster, lighter).
- **Vitest** + `@nuxt/test-utils` with `environment: 'nuxt'` for
  component tests.

## Local development

The dev server runs on `http://localhost:3001` (port 3000 is taken by
the Laravel API via Herd).

```bash
# From the repo root — starts admin + banner + edge workers as one process
bun run dev

# Or just admin in isolation
bun --filter @effect-cmp/admin run dev
```

The admin needs the Laravel API running too. From the repo root:

```bash
# In a second terminal
cd apps/api && herd open      # macOS Herd, default config
```

Then visit `http://localhost:3001`. Login with whatever user you
seeded via `php artisan tinker` or the relevant seeder.

## Configuration

Single runtime variable, exposed via `nuxt.config.ts`:

| Var                    | Default                 | Notes                                                  |
| ---------------------- | ----------------------- | ------------------------------------------------------ |
| `NUXT_PUBLIC_API_BASE` | `http://localhost:8000` | Laravel base URL. Override for staging/production.     |

Set as either an env var or in `.env.local` (gitignored).

## Scripts

| Command            | Notes                                                       |
| ------------------ | ----------------------------------------------------------- |
| `bun run dev`      | Dev server with HMR on :3001.                               |
| `bun run build`    | Production SPA build to `.output/`.                         |
| `bun run preview`  | Boot the built bundle locally.                              |
| `bun run typecheck`| `nuxt typecheck` — Vue + TS.                                |
| `bun run lint`     | oxlint.                                                     |
| `bun run format`   | oxfmt.                                                      |
| `bun run check`    | format:check + lint + typecheck. **Run before every push.** |
| `bun run fix`      | Auto-format + auto-fix lint.                                |
| `bun run test`     | Vitest in `environment: 'nuxt'` with `mockNuxtImport`.      |

## Project layout

```
app/
├── components/        Shared Vue components (CookiePolicyPanel, BannerHistoryPanel, ...)
├── composables/       Pure helpers only ($api, formatters, hash utils)
├── layouts/           Nuxt layouts (default with sidebar + org switcher)
├── middleware/        Route guards (auth, superuser)
├── pages/             File-based routing
│   ├── index.vue                   Dashboard with counts + getting-started
│   ├── domains/[id]/banner.vue     Banner editor + pending changes + history + preview
│   ├── domains/[id]/scans.vue      Per-domain scan history + trigger
│   ├── consent/index.vue           Consent log + filters + CSV export + receipt downloads
│   ├── cookies/index.vue           Canonical / Overrides / Review queue / Pending imports tabs
│   └── ...
├── stores/            Pinia stores — one per resource
│   ├── auth.ts                     /me, /login, /logout, organisation switching
│   ├── bannerConfigs.ts            draft / publish / diff / history / rollback
│   ├── cookiePolicies.ts           cookie-policy JSON per domain
│   └── ...
├── types/             TypeScript types for the resources (mirrors API resources)
└── assets/css/        Tailwind + Nuxt UI tokens
```

## Conventions

Read `~/.claude/CLAUDE.md` and the project `CLAUDE.md` for the
universal + project rules. Local highlights:

- **Path aliases only.** Use `~/foo` — never `../../foo` across folder
  boundaries.
- **Type every arrow function** — params + return.
- **Pinia stores own state.** Don't roll a `ref()` cache inside a
  composable — each call site would get its own copy.
- **Re-mount on org switch.** The default layout already keys its
  slot wrapper to `auth.organisationCacheKey`. Don't re-implement
  per-page.
- **Tests** mock `$api` via `mockNuxtImport()` from
  `@nuxt/test-utils`. No live API calls.

## Where things connect

- **API calls** go through `$api` (`composables/api.ts`) which sets
  `credentials: 'include'` for Sanctum and resolves relative URLs
  against `NUXT_PUBLIC_API_BASE`.
- **Sanctum** is handled by the Laravel side; the admin just needs
  `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS` to include
  `localhost:3001` in dev.
- **Auth state** lives in `useAuthStore()` — `user`, `isSuperuser`,
  `currentOrganisation`, `organisations[]`.

## Production

Builds to a static SPA bundle. Deploy as static assets behind a CDN
(Cloudflare Pages, Vercel static, Netlify, S3 + CloudFront). Set
`NUXT_PUBLIC_API_BASE` at build time so the bundle points at the right
API host.
