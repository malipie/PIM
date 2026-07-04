// GOLIVE #2126 — dedicated config for the Firefox + WebKit (Safari) matrix.
//
// Kept separate from playwright.config.ts (Chromium-only CI lane) so the
// cross-browser review runs on demand without adding two engines to every CI
// push. Run: pnpm exec playwright test --config=playwright.browser-matrix.config.ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  testMatch: /golive-2126-browser-matrix\.spec\.ts/,
  fullyParallel: false,
  workers: 1,
  timeout: 45_000,
  expect: { timeout: 15_000 },
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'playwright-report-browser-matrix' }],
  ],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://pim.localhost',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
