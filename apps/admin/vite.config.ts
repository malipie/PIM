import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// `__dirname` is not defined in ESM (`"type": "module"` in package.json) — derive it
// from `import.meta.url` so the @/* alias resolves correctly under both Vite dev
// and Vite build.
const here = path.dirname(fileURLToPath(import.meta.url));

// Real app version for the footer — package.json is the single source
// (release bumps it together with the git tag); APP_VERSION env can
// override at build time (e.g. CI stamping an exact tag).
const pkg = JSON.parse(readFileSync(path.resolve(here, 'package.json'), 'utf8')) as {
  version: string;
};
const appVersion = process.env.APP_VERSION ?? pkg.version;

// HMR through Caddy reverse proxy: WebSocket upgrades on the same single origin (pim.localhost).
// The Vite dev server listens on 0.0.0.0:5173 inside the container; Caddy forwards / and the
// HMR socket. See Caddyfile in repo root.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  define: {
    __APP_VERSION__: JSON.stringify(appVersion),
  },
  resolve: {
    alias: {
      '@': path.resolve(here, './src'),
    },
  },
  build: {
    // HARD-08 — bump the warning ceiling so the auto-named vendor
    // bucket (Refine + Radix + React) below 700 KB stops emitting
    // warnings every build. Per-route chunks (lazy-loaded) and the
    // explicit manualChunks below stay well under it.
    chunkSizeWarningLimit: 700,
    rollupOptions: {
      output: {
        // Pin a few vendor families into named chunks so they live in
        // dedicated files and stay cached across deploys when the
        // application code changes. The lazy() splits in App.tsx
        // handle per-page chunks automatically; manualChunks here is
        // only for libraries that load on every request.
        manualChunks: (id) => {
          if (!id.includes('node_modules')) return undefined;
          if (id.includes('react-router')) return 'router';
          if (id.includes('@refinedev')) return 'refine';
          if (id.includes('@tanstack/react-query')) return 'react-query';
          if (id.includes('lucide-react')) return 'icons';
          if (id.includes('@radix-ui')) return 'radix';
          if (id.includes('react-i18next') || id.includes('i18next')) return 'i18n';
          // Everything else stays in the auto-named vendor bucket.
          return undefined;
        },
      },
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    hmr: {
      // Caddy terminates TLS; let the browser connect on the public origin.
      clientPort: 443,
      protocol: 'wss',
      host: 'pim.localhost',
    },
    watch: {
      usePolling: true,
    },
  },
});
