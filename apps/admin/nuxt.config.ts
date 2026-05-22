export default defineNuxtConfig({
  compatibilityDate: '2026-05-01',
  devtools: { enabled: true },
  modules: ['@nuxt/ui', '@nuxt/eslint', '@pinia/nuxt'],
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
