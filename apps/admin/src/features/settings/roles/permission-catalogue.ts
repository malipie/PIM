/**
 * RBAC-P5-006 (#696) — i18n + ordering metadata for the permission
 * catalogue surfaced by `GET /api/permissions`.
 *
 * The backend exposes raw PRD-PIM-rbac §3.2 codes (`products.view`,
 * `categories.view`, `settings.users.manage`, ...). This module maps
 * the bare module slug onto a localisation key used by the matrix
 * grid headers AND fixes the row order so the UI lays the matrix out
 * the same way the PRD does — visual diff against the spec stays
 * cheap.
 */

import type { TFunction } from 'i18next';

export const PERMISSION_GROUP_ORDER: readonly string[] = [
  'platform',
  'tenant',
  'platform.tenants',
  'platform.audit',
  'platform.break_glass_recovery',
  'products',
  'categories',
  'multimedia',
  'modeling',
  'publications',
  'imports',
  'exports',
  'workflow',
  'agent',
  'settings.users',
  'settings.roles',
  'settings.tenant',
  'settings.billing',
  'settings.integrations',
  'settings.integration_secrets',
  'api_tokens.own',
  'api_tokens.all',
  'audit',
];

/**
 * Produces the user-facing label for a permission group module slug.
 * Falls back to a humanised version of the slug when no explicit i18n
 * key exists — keeps the matrix usable even after the backend adds a
 * new code before the FE has been redeployed.
 */
export function permissionGroupLabel(t: TFunction, slug: string): string {
  const key = `settings.roles.editor.group.${slug}`;
  const translated = t(key);
  if (translated !== key) {
    return translated;
  }
  return slug
    .split('.')
    .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
    .join(' / ');
}

/**
 * Produces the user-facing label for an action slug. Falls back to the
 * raw slug so unknown actions still show up in the matrix (rather than
 * silently disappearing) and the operator can ping us with the code.
 */
export function permissionActionLabel(t: TFunction, action: string): string {
  const key = `settings.roles.editor.action.${action}`;
  const translated = t(key);
  if (translated !== key) {
    return translated;
  }
  return action;
}

export function sortGroups<T extends { module: string }>(groups: T[]): T[] {
  const indexOf = (slug: string) => {
    const idx = PERMISSION_GROUP_ORDER.indexOf(slug);
    return idx === -1 ? Number.MAX_SAFE_INTEGER : idx;
  };
  return [...groups].sort((a, b) => {
    const ai = indexOf(a.module);
    const bi = indexOf(b.module);
    if (ai !== bi) return ai - bi;
    return a.module.localeCompare(b.module);
  });
}

/**
 * #2881 — the role builder offers the PRD catalogue only.
 *
 * Permissions live in one table but two catalogues: the PRD §3.2 codes
 * (`products.view`, `settings.roles.manage`, the ULV `object.*` verbs) and
 * the legacy `{resource}.{action}` grid (`object.read`, `object_type.admin`,
 * `integration.write`). **Endpoints honour the PRD codes for roles created
 * through the panel**, so offering the legacy grid alongside them puts
 * choices on screen that do not do what their name suggests — with
 * untranslated labels like "admin" and "write" next to proper ones.
 *
 * The split cannot be derived from the code text: `object.delete` is a
 * single row serving both catalogues. It comes from the backend, which
 * knows its own seeders.
 *
 * A legacy group is still shown when the role already holds something in
 * it, so editing such a role cannot silently drop grants the UI decided
 * not to display. In practice only the global `super_admin` holds legacy
 * codes and it is no longer editable from a tenant, but a builder that can
 * strip permissions it never showed is a bad shape regardless.
 */
export function visibleGroups<
  T extends { permissions: { code: string; catalogue?: 'prd' | 'legacy' }[] },
>(groups: T[], selectedCodes: ReadonlySet<string>): T[] {
  return groups
    .map((group) => ({
      ...group,
      permissions: group.permissions.filter(
        (permission) => permission.catalogue !== 'legacy' || selectedCodes.has(permission.code),
      ),
    }))
    .filter((group) => group.permissions.length > 0);
}
