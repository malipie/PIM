import { defineConfig } from 'vitepress';

// Served under https://<domain>/docs/ by the prod Caddy (file_server on
// /srv/docs). The base path makes every asset/link /docs-prefixed.
export default defineConfig({
  lang: 'pl-PL',
  title: 'Harmon PIM',
  description: 'Dokumentacja użytkownika i API systemu Harmon PIM',
  base: '/docs/',
  // The prod Caddy mounts apps/docs/dist as /srv/docs (docker-compose.prod.yml).
  outDir: '../dist',
  lastUpdated: false,
  // Source docs link into repo files (../../apps/api/src/...) — valid inside
  // the repository, dead on the published site. Alpha decision (operator
  // 2026-08-06): publish with relative source links unresolved rather than
  // fork the content; revisit when docs get a dedicated editing pass.
  ignoreDeadLinks: true,
  themeConfig: {
    nav: [
      { text: 'Przewodniki', link: '/guide/feeds' },
      { text: 'Dla developerów', link: '/developer/rbac' },
      { text: 'API (REST)', link: '/api.html', target: '_self' },
    ],
    sidebar: [
      {
        text: 'Przewodniki użytkownika',
        items: [
          { text: 'Feedy produktowe XML', link: '/guide/feeds' },
          { text: 'Workflow (przepływy akceptacji)', link: '/guide/workflow' },
          { text: 'Konfigurator API', link: '/guide/api-configurator' },
          { text: 'Agent AI', link: '/guide/agent' },
        ],
      },
      {
        text: 'Dla developerów i integratorów',
        items: [
          { text: 'Dokumentacja API (REST)', link: '/api.html', target: '_self' },
          { text: 'Webhooki (HMAC v2)', link: '/developer/webhooks' },
          { text: 'Kształty JSONB (kontrakt)', link: '/developer/jsonb-schemas' },
          { text: 'Role i uprawnienia (RBAC)', link: '/developer/rbac' },
          { text: 'Multi-tenancy', link: '/developer/multi-tenancy' },
          { text: 'Dodanie pola / endpointu', link: '/developer/adding-a-field-or-endpoint' },
        ],
      },
    ],
    outline: { level: [2, 3], label: 'Na tej stronie' },
    docFooter: { prev: 'Poprzednia', next: 'Następna' },
    search: { provider: 'local' },
  },
});
