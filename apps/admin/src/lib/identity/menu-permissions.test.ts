import { describe, expect, it } from 'vitest';

import type { Identity } from './identity';
import { isMenuRefVisible, MENU_PERMISSIONS } from './menu-permissions';

/**
 * CPDF-P5-05 (#2303) — the Catalog PDF menu entry reuses the exports read
 * permission (ADR-0027, no dedicated catalogs_pdf module). These tests pin
 * that contract so a future permission rename can't silently un-gate the area.
 */

function identityWith(permissions: readonly string[]): Identity {
  return {
    id: 'u1',
    email: 'u@demo.localhost',
    roles: [],
    tenant: null,
    lastLoginAt: null,
    passwordChangeRequired: false,
    permissions: new Set(permissions),
    localeScope: [],
    channelScope: [],
    attributeGroupScope: [],
    featureFlags: new Set<string>(),
  };
}

describe('MENU_PERMISSIONS.catalogs_pdf', () => {
  it('maps the catalog PDF entry to the exports read permissions', () => {
    expect(MENU_PERMISSIONS.catalogs_pdf).toEqual(['exports.view_own', 'exports.view_all']);
  });

  it('shows the entry to a user with exports.view_own', () => {
    expect(isMenuRefVisible(identityWith(['exports.view_own']), 'catalogs_pdf')).toBe(true);
  });

  it('shows the entry to a user with exports.view_all', () => {
    expect(isMenuRefVisible(identityWith(['exports.view_all']), 'catalogs_pdf')).toBe(true);
  });

  it('hides the entry from a user without any exports scope', () => {
    expect(isMenuRefVisible(identityWith(['products.view']), 'catalogs_pdf')).toBe(false);
  });

  it('hides the entry while identity is still loading', () => {
    expect(isMenuRefVisible(null, 'catalogs_pdf')).toBe(false);
  });
});
