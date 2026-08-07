import { defineConfig } from 'vitepress';

// Served under https://<domain>/docs/ by the prod Caddy (file_server on
// /srv/docs). The base path makes every asset/link /docs-prefixed.
export default defineConfig({
  lang: 'pl-PL',
  title: 'Harmon PIM',
  description: 'Dokumentacja użytkownika i API systemu Harmon PIM',
  base: '/docs/',
  // Adresy bez .html (/docs/selfhost zamiast /docs/selfhost.html). Edge Caddy
  // dokłada `try_files {path}.html`, więc bezpośrednie linki z .html też dalej
  // działają — nic z wcześniej rozesłanych adresów się nie psuje.
  cleanUrls: true,
  // The prod Caddy mounts apps/docs/dist as /srv/docs (docker-compose.prod.yml).
  outDir: '../dist',
  lastUpdated: false,
  // Source docs link into repo files (../../apps/api/src/...) — valid inside
  // the repository, dead on the published site. Decyzja operatora
  // 2026-08-06): publish with relative source links unresolved rather than
  // fork the content; revisit when docs get a dedicated editing pass.
  ignoreDeadLinks: true,
  head: [['link', { rel: 'icon', href: '/docs/favicon.svg' }]],
  themeConfig: {
    // Logotyp zamiast napisu — ten sam plik co w panelu, żeby oba interfejsy
    // pokazywały identyczny lockup. Klik prowadzi na stronę produktu, nie na
    // stronę główną dokumentacji (tam prowadzi pozycja w nawigacji).
    // Bez width/height: VitePress i tak narzuca własną wysokość, a sztywna
    // szerokość przy niej ściskała lockup w poziomie (proporcja pliku to
    // 6.45:1). Rozmiar ustawia brand.css — wyłącznie wysokością, szerokość
    // wyliczana automatycznie.
    logo: { src: '/brand-logo.png', alt: 'harmon PIM' },
    siteTitle: false,
    logoLink: { link: 'https://harmonpim.pl', target: '_self' },
    nav: [
      { text: 'Dokumentacja', link: '/' },
      { text: 'Przewodniki', link: '/guide/feeds' },
      { text: 'Dla developerów', link: '/developer/rbac' },
      { text: 'API (REST)', link: '/api.html', target: '_self' },
      { text: 'Self-hosting', link: '/selfhost' },
      { text: 'Licencja', link: '/licence' },
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
        text: 'Wdrożenie i licencja',
        items: [
          { text: 'Self-hosting', link: '/selfhost' },
          { text: 'Licencja', link: '/licence' },
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
