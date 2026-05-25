# @effectinsight/banner

The embeddable consent banner — vanilla TypeScript, built with Vite,
served from R2 behind `assets.effectinsight.com`. ≤15 KB gzipped budget.

## How clients embed it

```html
<script
  src="https://assets.effectinsight.com/loader.js"
  data-domain-id="<public-key>"
  async
></script>
```

The loader (`dist/loader.js`) is a stable URL that fetches the config
worker once and dynamically imports the hashed `cmp.<hash>.js` bundle.
Cache strategy:

- `loader.js` — `public, max-age=300, stale-while-revalidate=86400`
- `cmp.<hash>.js` — `public, max-age=31536000, immutable`

Hash bumps land via Vite at build time; loader points at the freshest
build through the config worker's `dist/loader.js` resolver.

## What it does at boot

1. Find the embed script tag (last match wins for multi-embed pages).
2. Fetch the signed config from the edge config worker (KV).
3. Push GCM v2 defaults to `dataLayer` _before_ anything else runs.
4. If the visitor has a valid stored consent cookie (`_effectinsight`) →
   replay the GCM update, unblock previously-blocked scripts, skip
   rendering the banner. RUM beacon fires for the short-circuit path.
5. If GPC is on AND the browser is sending it AND no stored consent →
   auto-record a `denied` event, write the cookie, skip rendering.
6. Otherwise render the banner with the visitor's best-matched locale
   ([locale.ts](./src/locale.ts)) — focus-trapped, ARIA-compliant.
7. On choice → write cookie, POST `/consent`, update GCM, unblock
   scripts. RUM beacon for the cold-paint path.

If the consent POST fails (5xx or network), the event lands in a
localStorage outbox ([`outbox.ts`](./src/outbox.ts)) and drains on
the next page load. The edge's `INSERT OR IGNORE` makes retries
idempotent.

## Source layout

```
src/
├── cmp.ts              Entry point. boot() orchestrates the above.
├── config.ts           Fetch the signed config from the edge.
├── consent.ts          Cookie read/write, event-shape builder, POST.
├── dom.ts              Banner DOM + focus trap + ARIA wiring.
├── styles.ts           Inline <style> generator (uses config colours).
├── gcm.ts              dataLayer GCM v2 default + update pushes.
├── scripts.ts          type="text/plain" → live <script> unblocker.
├── focus.ts            Focus trap helper + restorer.
├── csp.ts              CSP nonce detection + propagation.
├── locale.ts           Accept-Language → enabled-locale matcher.
├── outbox.ts           localStorage retry queue for failed POSTs.
├── rum.ts              First-paint + connection-type RUM beacon.
├── api.ts              window.cmp public API (see below).
└── types.ts            Wire-format types — must match packages/types.
```

## Public `window.cmp` API

The banner installs a singleton at `window.cmp` as soon as the script
runs (before the config fetch returns). Host pages use it for:

- `cmp.withdraw()` — for "Cookie preferences" footer links. Records a
  `withdrawn` event in the audit log AND re-opens the banner. GDPR
  Article 7(3) requires withdrawing to be as easy as giving.
- `cmp.openBanner()` — same, without first revoking.
- `cmp.getConsent()` — programmatic read of the current categories.
- `cmp.onConsentChange(cb)` — subscribe to consent events.

Full surface (9 methods): `getConsent`, `isReady`, `ready`,
`openBanner`, `withdraw`, `acceptAll`, `rejectAll`, `setConsent`,
`onConsentChange`. See [`api.ts`](./src/api.ts) for the contract.

Also fires `cmp:consent-change` as a CustomEvent on `document` for
non-JS-API integrations (Tag Manager–style DOM listeners).

## Scripts

| Command                    | Notes                                                                |
| -------------------------- | -------------------------------------------------------------------- |
| `bun run dev`              | Boot the dev stack — Vite watch + fixture host on :4173 in parallel. |
| `bun run build`            | Production build to `dist/`.                                         |
| `bun run preview`          | Serve `dist/` on :4173 for manual smoke tests.                       |
| `bun run serve:local`      | Fixture host (`test/e2e/fixtures/*.html`) on :4173.                  |
| `bun run typecheck`        | `tsc --noEmit`.                                                      |
| `bun run check`            | format:check + lint + typecheck.                                     |
| `bun run test`             | Vitest unit tests under `test/unit/`.                                |
| `bun run test:e2e`         | Playwright (Chromium + axe-core) under `test/e2e/`.                  |
| `bun run test:e2e:install` | Install Playwright's Chromium binary.                                |

The dev script (`scripts/dev-all.ts`) spawns Vite + the fixture host
under one foreground process with prefixed logs — Turbo picks it up
via `bun run dev` at the repo root.

## Hard requirements (must not regress)

- **Never throw uncaught.** `boot()` is wrapped in try/catch. A failed
  banner is worse than no banner — fail silent, let the host page
  continue.
- **WCAG 2.1 AA.** Focus trap, screen-reader copy, refusal as easy as
  acceptance, customise pane Esc-collapses. Locked in by axe-core
  Playwright runs in `test/e2e/axe.spec.ts`.
- **HMAC-verified config.** The edge worker rejects KV records whose
  signature doesn't recompute — cmp.js trusts the response shape.
- **CSP nonce support.** Style + unblocked scripts carry the host
  page's nonce when present.
- **p95 paint < 50ms.** RUM beacon (`rum.ts`) measures from boot to
  banner mount (or short-circuit). Pick up via `wrangler tail
effectinsight-consent | grep cmp_rum`.
- **Bundle budget ≤ 15 KB gzipped.** Anything that pushes us over
  needs to be tree-shakeable or behind a feature flag.

## Testing

- **Unit (Vitest)** — modules with no DOM dependencies (`locale`,
  `outbox`, `gcm`, `consent`) and the DOM ones in happy-dom.
- **E2E (Playwright + axe-core)** — full visitor flows under
  Chromium with strict CSP + GPC fixtures. Run a real banner against
  a fixture page in `test/e2e/fixtures/`.

```bash
bun run test           # vitest
bun run test:e2e       # playwright
```

## Production

`bun run build` outputs `dist/loader.js` + `dist/cmp.<hash>.js`.
Upload both to the R2 bucket referenced by the config worker
(`wrangler.config.toml`'s `BUNDLES` binding). The worker serves them
under the configured custom domain.
