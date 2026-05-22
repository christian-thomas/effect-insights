import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  build: {
    target: 'es2019',
    minify: 'terser',
    sourcemap: true,
    cssCodeSplit: false,
    lib: {
      entry: resolve(__dirname, 'src/cmp.ts'),
      formats: ['iife'],
      name: 'EffectCMP',
      fileName: () => 'cmp.js',
    },
  },
});
