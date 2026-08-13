import type { Identity } from './identity';
import { hasAnyPermission } from './identity';

/**
 * #2830 — section-level route gating.
 *
 * Sibling of {@link MENU_PERMISSIONS}, which decides what the sidebar
 * shows. This map decides what a URL is allowed to render, so a user who
 * types (or bookmarks, or is linked) a path they cannot use gets the 403
 * screen instead of a working form that only fails on submit.
 *
 * Why a prefix map rather than wrapping every `<Route>` in
 * `<PermissionRoute>`: the per-route wrapper is easy to forget, and that
 * is exactly how this bug happened — `/modeling/object-types/new` served
 * a full four-step wizard to a Catalog Manager, who then hit HTTP 403 on
 * save. With a prefix map, a new route inside an already-gated section
 * inherits the gate instead of silently opening a hole. `PermissionRoute`
 * stays valid for one-off routes whose permission differs from their
 * section (e.g. `/workflow/definitions` needing manage_definitions on top
 * of the section's workflow.view).
 *
 * Semantics mirror the menu map: "any of" the listed codes, longest
 * matching prefix wins, unmapped paths are open. Codes are the PRD §3.2
 * ones; keep them in sync with MENU_PERMISSIONS for the same area —
 * `route-permissions.test.ts` pins that.
 */
export const ROUTE_PERMISSIONS: ReadonlyArray<readonly [string, readonly string[]]> = [
  // Modeling — the schema surface. Catalog roles deliberately lack these,
  // which is why the section must not render for them at all.
  ['/modeling', ['modeling.view']],

  // Catalog objects.
  ['/products', ['products.view']],
  ['/objects', ['object.view', 'products.view']],
  ['/assets', ['multimedia.view']],
  ['/catalogs-pdf', ['exports.view_own', 'exports.view_all']],

  // Integrations. The API/XML configurator is an admin surface — it edits
  // connection profiles and credentials mapping.
  ['/integrations/imports', ['imports.view_own', 'imports.view_all']],
  ['/integrations/exports', ['exports.view_own', 'exports.view_all']],
  ['/integrations/api-configurator', ['settings.integrations.manage']],
  ['/api-profiles', ['api_profile.read', 'settings.integrations.manage']],

  ['/publications', ['publications.view']],
  ['/workflow', ['workflow.view']],

  // Agent surfaces — any agent capability is enough to see the inbox /
  // history; the destructive actions carry their own checks.
  ['/agent', ['agent.approve_pending', 'agent.bulk_actions', 'agent.schema_ops']],
];

/**
 * Permission codes required for `pathname`, or null when the path is not
 * gated. Longest prefix wins, so `/integrations/api-configurator` beats a
 * hypothetical `/integrations` entry.
 *
 * A prefix matches only on a segment boundary — `/products` must not gate
 * a future `/products-archive`.
 */
export function requiredPermissionsForPath(pathname: string): readonly string[] | null {
  let match: readonly string[] | null = null;
  let matchLength = -1;

  for (const [prefix, codes] of ROUTE_PERMISSIONS) {
    const isPrefix = pathname === prefix || pathname.startsWith(`${prefix}/`);
    if (isPrefix && prefix.length > matchLength) {
      match = codes;
      matchLength = prefix.length;
    }
  }

  return match;
}

/**
 * Whether the caller may render `pathname`.
 *
 * A null identity (still loading) is NOT treated as forbidden here — the
 * caller renders a loading state instead, otherwise every hard reload
 * would flash the 403 screen before /api/auth/me lands.
 */
export function canAccessPath(identity: Identity | null, pathname: string): boolean {
  const required = requiredPermissionsForPath(pathname);
  if (null === required) {
    return true;
  }
  if (!identity) {
    return true;
  }

  return hasAnyPermission(identity, required);
}
