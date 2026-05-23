export default defineNuxtConfig({
  compatibilityDate: '2026-05-01',
  devtools: { enabled: true },
  // Authenticated admin dashboard — no SEO value from SSR, and SSR would need
  // to proxy the Sanctum session cookie from incoming Nuxt requests to Laravel
  // upstream, which adds complexity for no benefit. Going client-only also
  // avoids devalue payload-serialisation issues from Error instances landing
  // in Pinia state during failed server-side fetches.
  ssr: false,
  modules: ['@nuxt/ui', '@pinia/nuxt'],
  css: ['~/assets/css/main.css'],
  icon: {
    // Skip the @nuxt/icon server API. It's wrapped in defineCachedEventHandler
    // internally, which under h3 v2 RC + Nitro 2.13 cache layer dies on the
    // synthetic node-mock-http event (Invalid URL inside getQuery). Routing
    // through iconify-icon instead bypasses that path entirely.
    provider: 'iconify',
    clientBundle: {
      scan: true,
      includeCustomCollections: true,
      sizeLimitKb: 1024,
    },
  },
  typescript: {
    strict: true,
    typeCheck: false,
  },
  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8000',
    },
  },
  vite: {
    optimizeDeps: {
      include: ['zod'],
    },
  },
});
