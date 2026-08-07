/**
 * UI re-align (#865) — fallback persona sub-label keyed by role `code`.
 * Source of truth: `Zrodla/Front_Claude_Design/PIM-nowoczesny/settings/data.jsx`
 * `RBAC_ROLES[].persona`. Backend will eventually expose this via
 * Role.persona; until then RolesListView renders these literals.
 */
export const ROLE_PERSONAS: Record<string, string> = {
  super_admin: 'Marcin · operator platformy',
  tenant_owner: 'Tomasz · właściciel firmy',
  admin: 'Piotr · IT / co-owner',
  catalog_manager: 'Kasia · operacje katalogu',
  marketing: 'Magda · content & tłumaczenia',
  content_editor: 'Magda · content & tłumaczenia',
  modeler: 'Adam · modeler schematu',
  information_architect: 'Adam · modeler schematu',
  integration_manager: 'Piotr · konsultant integracji',
  channel_manager: 'sales · multi-channel',
  approver: 'reviewer · zatwierdzający',
  viewer: 'auditor · accountant · observer',
};

/** Stable fallback for custom roles — design uses `custom · stworzona przez klienta`. */
export const CUSTOM_PERSONA = 'custom · stworzona przez klienta';
