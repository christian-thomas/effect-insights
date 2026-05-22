import withNuxt from './.nuxt/eslint.config.mjs';

export default withNuxt(
  {
    // Vue 3 supports fragments (multiple root elements). Nuxt UI v4 dashboard
    // pages use the `<UDashboardNavbar />` + content sibling pattern by design.
    rules: {
      'vue/no-multiple-template-root': 'off',
    },
  },
  {
    // Tests mock Nuxt imports via mockNuxtImport(), which must appear before
    // the import it shadows — import/first conflicts with that hoisting.
    files: ['test/**/*.spec.ts'],
    rules: {
      'import/first': 'off',
    },
  },
);
