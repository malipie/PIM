import type { Identity } from './identity';
import { hasAnyPermission } from './identity';

/**
 * RBAC-P4-005 (#682) — sidebar / menu permission gating map.
 *
 * Each entry maps a menu `ref` (the stable backend identifier used by
 * `useEffectiveMenu()` and the system-menu registry) to the PRD §3.2
 * permission codes that should make the entry visible. The semantics
 * are "any of" — the menu entry shows up when the caller holds at
 * least one of the listed codes.
 *
 * Entries not in the map are considered public — the legacy behaviour
 * where the sidebar is identical for every authenticated user. Adding
 * a new gated entry is a single map row; nothing else moves.
 *
 * Cross-cutting note — backend `useEffectiveMenu()` is itself permission-
 * aware in Phase 6 (it filters server-side once the
 * `#[RequiresPermission]` retrofit lands). The frontend filter here is
 * the second-line defence + the UX layer that hides "appears coming
 * soon" links before the backend trims the list.
 */
export const MENU_PERMISSIONS: Readonly<Record<string, readonly string[]>> = {
  // Catalog
  products: ['products.view'],
  categories: ['categories.view'],
  multimedia: ['multimedia.view'],

  // Catalog PDF (web-to-print) — reuses the exports read permission (ADR-0027,
  // no dedicated catalogs_pdf module); own scope is enough to show the entry.
  catalogs_pdf: ['exports.view_own', 'exports.view_all'],

  // Modeling (per PRD §3.2 macierz — Modeler / Owner / Admin)
  modeling: ['modeling.view'],

  // Settings — single-permission collapse covers every sub-item; users
  // who lack everything see the entry hidden entirely.
  settings: [
    'settings.users.manage',
    'settings.roles.manage',
    'settings.tenant.manage',
    'settings.integrations.manage',
    'settings.billing.manage',
  ],

  // Imports / Exports — own scope is enough to show the entry.
  imports: ['imports.view_own', 'imports.view_all'],
  exports: ['exports.view_own', 'exports.view_all'],

  // Workflow inbox (Approver + Owner per macierz).
  workflow: ['workflow.view'],

  // API configurator visibility tied to integrations management.
  api_configurator: ['settings.integrations.manage'],
};

/**
 * Decides whether the given menu `ref` should be visible to the caller.
 *
 *   - Identity still loading → return false. The sidebar renders the
 *     fallback (cached) list and swaps once /api/auth/me lands; the
 *     50–200ms flash with no permissions is preferable to flashing
 *     forbidden entries.
 *   - Unmapped `ref` → visible (treated as public).
 *   - Mapped `ref`   → visible iff caller holds at least one of the
 *     mapped codes.
 */
export function isMenuRefVisible(identity: Identity | null, ref: string): boolean {
  if (!identity) {
    return false;
  }
  const required = MENU_PERMISSIONS[ref];
  if (!required) {
    return true;
  }
  return hasAnyPermission(identity, required);
}
