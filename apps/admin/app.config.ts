export default defineAppConfig({
  ui: {
    icons: {
      // Nuxt UI defaults to `i-heroicons-x-mark-20-solid` which isn't bundled
      // in this project — the icon is omitted and the close button renders
      // blank. Pin to a lucide icon that's already loaded.
      close: 'i-lucide-x',
    },
  },
});
