import type { Page } from '@playwright/test';

export interface JsonResponse<T> {
  status: number;
  body: T;
}

/**
 * POST JSON from inside the browser context.
 *
 * `page.request` issues the call from Node, which does not resolve
 * `*.localhost` names — locally that surfaces as `getaddrinfo ENOTFOUND
 * pim.localhost` even though the very same URL loads fine in the page.
 * Chromium resolves it, so we run the fetch there. Same-origin credentials
 * apply, so a session established in the page is honoured here too.
 *
 * The page must already be on the app origin (call `page.goto` first).
 */
export async function postJson<T>(
  page: Page,
  path: string,
  body: unknown,
  headers: Record<string, string> = {},
): Promise<JsonResponse<T>> {
  return page.evaluate(
    async ({ path, body, headers }) => {
      const response = await fetch(path, {
        method: 'POST',
        headers: { 'content-type': 'application/json', accept: 'application/json', ...headers },
        body: JSON.stringify(body),
      });
      return {
        status: response.status,
        body: (await response.json().catch(() => null)) as never,
      };
    },
    { path, body, headers },
  );
}
