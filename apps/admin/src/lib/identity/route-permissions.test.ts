import { describe, expect, it } from 'vitest';

import { MENU_PERMISSIONS } from './menu-permissions';
import { canAccessPath, ROUTE_PERMISSIONS, requiredPermissionsForPath } from './route-permissions';

function identityWith(codes: string[]) {
  return {
    permissions: new Set(codes),
  } as unknown as Parameters<typeof canAccessPath>[0];
}

const CATALOG_MANAGER = identityWith([
  'products.view',
  'object.view',
  'categories.view',
  'multimedia.view',
  'imports.view_own',
  'exports.view_own',
  'workflow.view',
]);

/**
 * #2830 — the map that decides whether a URL may render at all.
 */
describe('requiredPermissionsForPath', () => {
  it('gates a section and everything under it', () => {
    expect(requiredPermissionsForPath('/modeling')).toEqual(['modeling.view']);
    expect(requiredPermissionsForPath('/modeling/object-types/new')).toEqual(['modeling.view']);
  });

  it('lets the most specific prefix win', () => {
    // /integrations/api-configurator is an admin surface; /integrations/imports is not.
    expect(requiredPermissionsForPath('/integrations/api-configurator/feeds/12')).toEqual([
      'settings.integrations.manage',
    ]);
    expect(requiredPermissionsForPath('/integrations/imports/sessions')).toEqual([
      'imports.view_own',
      'imports.view_all',
    ]);
  });

  it('matches on segment boundaries only', () => {
    expect(requiredPermissionsForPath('/products/42')).toEqual(['products.view']);
    // A different section that merely starts with the same characters.
    expect(requiredPermissionsForPath('/products-archive')).toBeNull();
  });

  it('leaves unmapped paths open', () => {
    expect(requiredPermissionsForPath('/dashboard')).toBeNull();
    expect(requiredPermissionsForPath('/settings/users')).toBeNull();
  });
});

describe('canAccessPath', () => {
  it('refuses the modeling wizard to a role without modeling codes', () => {
    // The exact case from the report: Catalog Manager walked the whole
    // object-type wizard and only met HTTP 403 on save.
    expect(canAccessPath(CATALOG_MANAGER, '/modeling/object-types/new')).toBe(false);
  });

  it('allows sections the role does hold', () => {
    expect(canAccessPath(CATALOG_MANAGER, '/products')).toBe(true);
    expect(canAccessPath(CATALOG_MANAGER, '/integrations/imports/sessions')).toBe(true);
  });

  it('refuses the API configurator without integrations management', () => {
    expect(canAccessPath(CATALOG_MANAGER, '/integrations/api-configurator')).toBe(false);
  });

  it('does not block while identity is still unknown', () => {
    // A hard reload must not flash 403 before /api/auth/me resolves; the
    // API stays the real enforcement point.
    expect(canAccessPath(null, '/modeling')).toBe(true);
  });
});

describe('route and menu maps agree', () => {
  // Hiding a sidebar entry and blocking its URL must not drift apart —
  // otherwise an entry disappears from the menu but its route stays open
  // (or the reverse: a visible entry leads straight to a 403 screen).
  const SHARED: ReadonlyArray<readonly [string, string]> = [
    ['products', '/products'],
    ['multimedia', '/assets'],
    ['modeling', '/modeling'],
    ['catalogs_pdf', '/catalogs-pdf'],
    ['imports', '/integrations/imports'],
    ['exports', '/integrations/exports'],
    ['workflow', '/workflow'],
    ['api_configurator', '/integrations/api-configurator'],
  ];

  it.each(SHARED)('%s and %s require the same codes', (menuRef, path) => {
    const menuCodes = [...(MENU_PERMISSIONS[menuRef] ?? [])].sort();
    const routeCodes = [...(requiredPermissionsForPath(path) ?? [])].sort();

    expect(routeCodes).toEqual(menuCodes);
  });

  it('keeps every route prefix absolute and slash-free at the end', () => {
    for (const [prefix] of ROUTE_PERMISSIONS) {
      expect(prefix.startsWith('/')).toBe(true);
      expect(prefix.endsWith('/')).toBe(false);
    }
  });
});
