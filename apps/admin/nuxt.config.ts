export default defineNuxtConfig({
  compatibilityDate: '2026-05-01',
  devtools: { enabled: true },
  modules: ['@nuxt/ui', '@nuxt/eslint', '@pinia/nuxt'],
  css: ['~/assets/css/main.css'],
  icon: {
    serverBundle: { collections: ['lucide', 'simple-icons'] },
    clientBundle: {
      scan: true,
      sizeLimitKb: 512,
    },
  },
  nitro: {
    routeRules: {
      // h3 v2 RC + Nitro cache layer mishandles the synthetic node-mock-http
      // event the icon API endpoint is wrapped with — bypass caching here.
      '/api/_nuxt_icon/**': { cache: false, swr: false },
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
