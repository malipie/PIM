import { defineConfig, devices } from '@playwright/test';

const ciMode = !!process.env.CI;

/**
 * Playwright config for the admin E2E suite.
 *
 * Tests target the same single-origin URL the browser uses
 * (`https://pim.localhost`) — the Caddy proxy in front of the FrankenPHP
 * worker and the Vite dev server lives on this hostname locally and in CI
 * (CI maps `pim.localhost` to 127.0.0.1 via /etc/hosts before running).
 * Self-signed cert from Caddy is accepted via `ignoreHTTPSErrors`.
 *
 * The stack is brought up by `docker compose up -d` outside Playwright —
 * we don't use the `webServer` option because we need the full
 * Postgres+API+Caddy combo, not just Vite. Locally, run `pnpm stack:up`
 * before `pnpm e2e`.
 */
export default defineConfig({
  // HARD-09 — clear `auth_login` + `auth_refresh` rate-limiter
  // buckets before the suite. modeling-shell.spec.ts hit 429s
  // accumulated across dev sessions; without the reset the JWT
  // refresh path fails mid-spec and the user redirects to /login.
  // The setup script is best-effort — if docker is unreachable
  // (operator running specs against a remote stack) the
  // `loginAsAdmin` retry-on-429 in helpers/auth.ts handles the
  // residual cases.
  globalSetup: './e2e/global-setup.ts',
  testDir: './e2e',
  // The GOLIVE #2126 cross-browser matrix runs Firefox + WebKit via its own
  // dedicated config (playwright.browser-matrix.config.ts). Keep it out of the
  // default Chromium CI lane — its console-clean gate is engine/host-specific.
  testIgnore: '**/golive-2126-browser-matrix.spec.ts',
  fullyParallel: false,
  forbidOnly: ciMode,
  retries: ciMode ? 2 : 0,
  workers: 1,
  reporter: ciMode ? [['github'], ['html', { open: 'never' }]] : 'list',
  timeout: 30_000,
  expect: { timeout: 10_000 },

  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://pim.localhost',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10_000,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
