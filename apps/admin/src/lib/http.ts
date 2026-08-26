/**
 * Single-origin fetch wrapper.
 *
 * The admin always talks to /api/* on the same Caddy origin (CLAUDE.md →
 * "Single-origin przez Caddy w FrankenPHP — TYLKO TAK"). No CORS, no separate
 * base URL configuration in the browser.
 *
 * Auth model after #29:
 *   - The access JWT lives in module-scoped memory only — never localStorage.
 *     XSS that reads `localStorage` cannot lift the token. The price is that
 *     a hard reload starts with no token; we recover it with a silent
 *     POST /api/auth/refresh against the path-scoped HttpOnly cookie.
 *   - `jsonFetch` retries every 401 (except on /api/auth/login and
 *     /api/auth/refresh themselves) by triggering one refresh and replaying
 *     the original request. Concurrent 401s share a single in-flight refresh
 *     promise so a burst of Refine queries does not trip the backend's
 *     "reused refresh token" theft-detection path.
 */

const LOGIN_PATH = '/api/auth/login';
const REFRESH_PATH = '/api/auth/refresh';

let accessToken: string | null = null;
let refreshInFlight: Promise<string> | null = null;

export class HttpError extends Error {
  readonly status: number;
  readonly body: unknown;

  constructor(status: number, body: unknown) {
    super(`HTTP ${status}`);
    this.name = 'HttpError';
    this.status = status;
    this.body = body;
  }
}

/**
 * Pull the RFC 7807 `detail` out of a thrown {@see HttpError} so callers can
 * show the server's reason (e.g. #1179 duplicate-identifier 409) instead of a
 * generic message. Returns null for non-HttpError throwables or bodies
 * without a string `detail`.
 */
export function httpErrorDetail(error: unknown): string | null {
  if (
    error instanceof HttpError &&
    typeof error.body === 'object' &&
    error.body !== null &&
    'detail' in error.body &&
    typeof (error.body as { detail: unknown }).detail === 'string'
  ) {
    return (error.body as { detail: string }).detail;
  }
  return null;
}

export function getAccessToken(): string | null {
  return accessToken;
}

export function setAccessToken(token: string): void {
  accessToken = token;
}

export function clearAccessToken(): void {
  accessToken = null;
}

export interface JsonRequestInit {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  contentType?: string;
  accept?: string;
  query?: Record<string, string | number | undefined>;
  /**
   * #3034 — pass React Query's `queryFn({ signal })` through to `fetch` so
   * leaving a page aborts its in-flight requests instead of leaving them to
   * occupy server workers ahead of whatever the user navigated to.
   *
   * The signal survives the 401 → refresh → replay path: a request aborted
   * mid-refresh must not come back as a replay nobody is waiting for.
   */
  signal?: AbortSignal;
}

interface InternalJsonRequestInit extends JsonRequestInit {
  /**
   * Set on the recursive call after a 401 → silent refresh → retry. Prevents
   * unbounded recursion if the retried request also returns 401.
   */
  retryAfterRefresh?: boolean;
}

export async function jsonFetch<T = unknown>(path: string, init: JsonRequestInit = {}): Promise<T> {
  return fetchInternal<T>(path, init);
}

async function fetchInternal<T>(path: string, init: InternalJsonRequestInit): Promise<T> {
  if (!accessToken && refreshInFlight) {
    // Auth bootstrap race (#2199): after a hard reload the in-memory token is
    // empty while authProvider.check() is already refreshing it. An
    // optimistic request fired now would go out without a Bearer, 401, and
    // land in the console as noise even though the retry-after-refresh path
    // heals it. Wait for the in-flight refresh instead of racing it.
    await refreshInFlight.catch(() => {
      // Refresh failed — proceed tokenless; the caller's 401/onError path
      // owns the redirect to /login.
    });
  }

  const url = init.query ? appendQuery(path, init.query) : path;
  const headers = new Headers();
  headers.set('accept', init.accept ?? 'application/ld+json');

  if (accessToken) {
    headers.set('authorization', `Bearer ${accessToken}`);
  }

  let body: BodyInit | undefined;
  if (init.body !== undefined) {
    if (init.body instanceof FormData) {
      // Multipart upload — let the browser set Content-Type with the
      // boundary. Used by the import wizard's parse-preview call (file
      // bytes + encoding/delimiter hints).
      body = init.body;
    } else {
      headers.set('content-type', init.contentType ?? 'application/ld+json');
      body = JSON.stringify(init.body);
    }
  }

  const response = await fetch(url, {
    method: init.method ?? 'GET',
    headers,
    body,
    credentials: 'same-origin',
    signal: init.signal,
  });

  if (response.status === 401 && shouldAttemptRefresh(path, init)) {
    try {
      await refreshAccessToken();
    } catch {
      // Refresh failed — let the original 401 propagate so authProvider.onError
      // can boot the user. We deliberately do not throw the refresh error;
      // callers expect the status from the original endpoint.
      return throwHttpError<T>(response);
    }
    return fetchInternal<T>(path, { ...init, retryAfterRefresh: true });
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const text = await response.text();
  const parsed = text ? safeJsonParse(text) : undefined;

  if (!response.ok) {
    throw new HttpError(response.status, parsed);
  }

  // Defence against the 2026-05-13 white-screen incident: FrankenPHP can
  // answer 200 with a `text/html` PHP fatal-error page when an endpoint
  // exceeds `max_execution_time`. Without this guard, `safeJsonParse`
  // returns the HTML *string* typed as `T` and the caller crashes on the
  // next property access (e.g. `response.created.length`).
  const contentType = response.headers.get('content-type') ?? '';
  const expectsJson =
    /\b(application\/(ld\+|merge-patch\+|problem\+)?json|application\/javascript)\b/i.test(
      contentType,
    );
  if (text && !expectsJson && typeof parsed === 'string') {
    // Include a body snippet in the detail so DevTools shows what the
    // server actually returned (HTML fatal page? auth refresh redirect?
    // Vite index.html fallback?). 200 chars is enough to spot patterns
    // without flooding the toast.
    const snippet = parsed.length > 200 ? `${parsed.slice(0, 200)}…` : parsed;
    throw new HttpError(response.status, {
      type: 'about:blank',
      title: 'Unexpected response',
      detail: `Server returned ${response.status} with ${contentType || 'no'} content-type when JSON was expected. Body starts with: ${snippet}`,
    });
  }

  return parsed as T;
}

/**
 * #2743 — raw `fetch` that still rides the shared auth lifecycle.
 *
 * Callers that need the raw {@link Response} (binary downloads, streamed
 * bodies, hand-rolled content-type negotiation) used to build the request
 * themselves with `getAccessToken()`. That skipped the 401 → refresh → replay
 * path, so the FIRST such call after a hard reload — when the in-memory token
 * is gone but the refresh cookie is still valid — failed instead of recovering.
 *
 * The response is returned untouched: this helper owns authentication, the
 * caller owns interpretation.
 */
export async function rawFetch(path: string, init: RequestInit = {}): Promise<Response> {
  const send = async (): Promise<Response> => {
    const headers = new Headers(init.headers);
    if (accessToken) {
      headers.set('authorization', `Bearer ${accessToken}`);
    }
    return fetch(path, { ...init, headers, credentials: 'same-origin' });
  };

  if (!accessToken && refreshInFlight) {
    await refreshInFlight.catch(() => {
      // Refresh failed — send tokenless and let the caller handle the 401.
    });
  }

  const response = await send();
  if (response.status !== 401) {
    return response;
  }

  try {
    await refreshAccessToken();
  } catch {
    return response;
  }

  return send();
}

/**
 * POST /api/auth/refresh, parse `{token}`, store it in memory.
 *
 * The single-flight guard collapses concurrent callers onto one network
 * request. Without it, a burst of expired-Bearer 401s from Refine's parallel
 * queries would each call /refresh — the second call would hit the backend's
 * already-used token branch and revoke the whole family.
 */
export async function refreshAccessToken(): Promise<string> {
  if (!refreshInFlight) {
    refreshInFlight = doRefresh().finally(() => {
      refreshInFlight = null;
    });
  }
  return refreshInFlight;
}

async function doRefresh(): Promise<string> {
  const response = await fetch(REFRESH_PATH, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { accept: 'application/json' },
  });

  if (!response.ok) {
    accessToken = null;
    const text = await response.text().catch(() => '');
    throw new HttpError(response.status, text ? safeJsonParse(text) : undefined);
  }

  const payload = (await response.json()) as { token?: unknown };
  if (typeof payload.token !== 'string' || payload.token === '') {
    accessToken = null;
    throw new HttpError(response.status, payload);
  }

  accessToken = payload.token;
  return payload.token;
}

function shouldAttemptRefresh(path: string, init: InternalJsonRequestInit): boolean {
  if (init.retryAfterRefresh) return false;
  // Use startsWith so query strings on the same endpoint still match.
  return !path.startsWith(LOGIN_PATH) && !path.startsWith(REFRESH_PATH);
}

async function throwHttpError<T>(response: Response): Promise<T> {
  const text = await response.text().catch(() => '');
  throw new HttpError(response.status, text ? safeJsonParse(text) : undefined);
}

function appendQuery(path: string, query: Record<string, string | number | undefined>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined) continue;
    params.set(key, String(value));
  }
  const qs = params.toString();
  if (!qs) return path;
  return path.includes('?') ? `${path}&${qs}` : `${path}?${qs}`;
}

function safeJsonParse(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
