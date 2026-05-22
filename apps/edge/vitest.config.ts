import { cloudflareTest } from '@cloudflare/vitest-pool-workers';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [
    cloudflareTest({
      miniflare: {
        compatibilityDate: '2026-05-01',
        compatibilityFlags: ['nodejs_compat'],
        kvNamespaces: ['BANNER_CONFIGS'],
        r2Buckets: ['BUNDLES'],
        d1Databases: ['CONSENT_DB'],
        bindings: {
          ENVIRONMENT: 'test',
          LARAVEL_INGEST_URL: 'https://api.test/mirror',
        },
      },
    }),
  ],
});
