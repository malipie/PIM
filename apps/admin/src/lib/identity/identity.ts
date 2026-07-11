/**
 * RBAC-P4-001 (#678) — identity bootstrap shape returned by GET /api/auth/me.
 *
 * Mirrors `App\Identity\Presentation\MeController` 1:1 after the
 * Phase 4 extension that adds the flat PRD §3.2 permission codes
 * plus locale / channel / attribute_group scopes (each scope
 * `[]`/`["*"]` = no restriction, matching `UserRole` storage).
 *
 * Consumers should always go through {@link useIdentity}; the raw
 * response is exported for the rare callsite that needs the wire shape
 * (e.g. tests asserting backward compatibility).
 */
export interface MeResponse {
  id: string;
  email: string;
  /** Symfony Security role strings (legacy + scoped roles). */
  roles: string[];
  tenant: { id: string; code: string; name: string; plan?: string } | null;
  last_login_at: string | null;
  /**
   * Manual user creation (#867) — TRUE when an admin set the user's
   * password via POST /api/users. AuthedRoute redirects to
   * `/first-login-password` while the flag is on and blocks the rest of
   * the SPA until the user picks a personal password. Cleared by the
   * change-password endpoint server-side.
   */
  password_change_required: boolean;
  /** PRD §3.2 permission codes — flat strings like `products.view`. */
  permissions: string[];
  /** PRD §3.6 locale narrowing. */
  locale_scope: string[];
  /** PRD §3.7 channel narrowing. */
  channel_scope: string[];
  /** Modeler / channel-scoped role group narrowing. */
  attribute_group_scope: string[];
  /**
   * WFL-P5-03 (#2433) — runtime deployment feature flags (e.g.
   * `workflow_custom_definitions`). Optional: older backends omit it.
   */
  feature_flags?: Record<string, boolean>;
}

/**
 * Hydrated identity payload consumed by `useIdentity()` and the
 * permission helpers. Wraps the wire shape with O(1) Set-backed
 * permission lookup and convenience getters; everything immutable
 * and serialisable so React's Suspense / dehydration can roundtrip it.
 */
export interface Identity {
  id: string;
  email: string;
  roles: string[];
  tenant: { id: string; code: string; name: string; plan?: string } | null;
  lastLoginAt: string | null;
  /** See {@link MeResponse.password_change_required}. */
  passwordChangeRequired: boolean;
  permissions: ReadonlySet<string>;
  localeScope: string[];
  channelScope: string[];
  attributeGroupScope: string[];
  /** Enabled runtime feature flags — see {@link MeResponse.feature_flags}. */
  featureFlags: ReadonlySet<string>;
}

const WILDCARD = '*';

/**
 * Hydrate the wire shape into the consumer-facing {@link Identity}.
 * Pure / synchronous so it can be called both in the React Query
 * select() function and in unit tests without bootstrapping
 * any provider.
 */
export function hydrateIdentity(response: MeResponse): Identity {
  return {
    id: response.id,
    email: response.email,
    roles: response.roles,
    tenant: response.tenant,
    lastLoginAt: response.last_login_at,
    passwordChangeRequired: response.password_change_required ?? false,
    permissions: new Set(response.permissions),
    localeScope: response.locale_scope,
    channelScope: response.channel_scope,
    attributeGroupScope: response.attribute_group_scope,
    featureFlags: new Set(
      Object.entries(response.feature_flags ?? {})
        .filter(([, enabled]) => enabled)
        .map(([flag]) => flag),
    ),
  };
}

export function hasFeature(identity: Identity | null, flag: string): boolean {
  return identity?.featureFlags.has(flag) ?? false;
}

export function hasPermission(identity: Identity | null, code: string): boolean {
  if (!identity) {
    return false;
  }
  return identity.permissions.has(code);
}

export function hasAnyPermission(identity: Identity | null, codes: readonly string[]): boolean {
  if (!identity) {
    return false;
  }
  return codes.some((code) => identity.permissions.has(code));
}

export function hasAllPermissions(identity: Identity | null, codes: readonly string[]): boolean {
  if (!identity) {
    return false;
  }
  return codes.every((code) => identity.permissions.has(code));
}

/**
 * PRD §3.6 — empty scope OR explicit `["*"]` mean no restriction. A
 * narrowed list grants only its members. The same convention applies
 * to {@link canEditChannel}.
 */
export function canEditLocale(identity: Identity | null, locale: string): boolean {
  if (!identity) {
    return false;
  }
  return scopeAllows(identity.localeScope, locale);
}

export function canEditChannel(identity: Identity | null, channel: string): boolean {
  if (!identity) {
    return false;
  }
  return scopeAllows(identity.channelScope, channel);
}

export function canEditAttributeGroup(identity: Identity | null, groupCode: string): boolean {
  if (!identity) {
    return false;
  }
  return scopeAllows(identity.attributeGroupScope, groupCode);
}

function scopeAllows(scope: string[], value: string): boolean {
  if (scope.length === 0 || scope.includes(WILDCARD)) {
    return true;
  }
  return scope.includes(value);
}
