/**
 * Docs content sync — pulls the publishable subset of repo documentation
 * into the VitePress content tree at build time.
 *
 * ALLOW-LIST, not deny-list: only files named here are ever published.
 * A new internal report dropped into docs/ can never leak onto the site
 * (docs/security, docs/audit, docs/operations etc. are simply never
 * copied). Source files stay the single source of truth in docs/ —
 * do not edit the copies under content/ (they are gitignored).
 */
import { copyFileSync, cpSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
const contentDir = resolve(here, '../content');
const publicDir = resolve(contentDir, 'public');

/** repo path → content path (relative) */
const ALLOWLIST = {
  'docs/export/feeds.md': 'guide/feeds.md',
  'docs/workflow.md': 'guide/workflow.md',
  'docs/integration/api-configurator-guide.md': 'guide/api-configurator.md',
  'docs/agent-user-guide.md': 'guide/agent.md',
  'docs/rbac.md': 'developer/rbac.md',
  'docs/multi-tenancy.md': 'developer/multi-tenancy.md',
  'docs/api/webhooks.md': 'developer/webhooks.md',
  'docs/api/jsonb-schemas.md': 'developer/jsonb-schemas.md',
  'docs/development/adding-a-field-or-endpoint.md': 'developer/adding-a-field-or-endpoint.md',
};

// Clean previous synced output (idempotent builds).
for (const dir of ['guide', 'developer']) {
  rmSync(resolve(contentDir, dir), { recursive: true, force: true });
}

for (const [src, dest] of Object.entries(ALLOWLIST)) {
  const target = resolve(contentDir, dest);
  mkdirSync(dirname(target), { recursive: true });
  copyFileSync(resolve(repoRoot, src), target);
}

// OpenAPI snapshot — the CI "spec drift" gate guarantees freshness, so the
// site renders the committed file (offline, no live stack needed).
mkdirSync(resolve(publicDir, 'api-spec'), { recursive: true });
copyFileSync(resolve(repoRoot, 'docs/api-spec/v0.json'), resolve(publicDir, 'api-spec', 'v0.json'));

// Scalar standalone bundle — self-hosted (the edge CSP blocks CDNs).
mkdirSync(resolve(publicDir, 'vendor'), { recursive: true });
cpSync(
  resolve(here, '../node_modules/@scalar/api-reference/dist/browser/standalone.js'),
  resolve(publicDir, 'vendor', 'scalar-standalone.js'),
);

console.log(`[docs] synced ${Object.keys(ALLOWLIST).length} pages + api-spec + scalar bundle`);
