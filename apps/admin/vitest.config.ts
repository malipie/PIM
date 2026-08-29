import path from 'node:path';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  define: {
    // Mirrors the vite.config define so components using the build-time
    // version constant also compile under vitest.
    __APP_VERSION__: JSON.stringify('0.0.0-test'),
  },
  resolve: {
    alias: { '@': path.resolve(import.meta.dirname, 'src') },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./vitest.setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    globals: true,
  },
});
