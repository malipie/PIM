/**
 * RBAC-P4-001 (#678) — identity module surface for admin app.
 */
export {
  canEditAttributeGroup,
  canEditChannel,
  canEditLocale,
  hasAllPermissions,
  hasAnyPermission,
  hasFeature,
  hasPermission,
  hydrateIdentity,
  type Identity,
  type MeResponse,
} from './identity';
export { isMenuRefVisible, MENU_PERMISSIONS } from './menu-permissions';
export {
  decideFieldMode,
  type FieldRenderMode,
  isRestrictedFieldEnvelope,
  type RestrictedFieldEnvelope,
  type RestrictedFieldValue,
} from './restricted-field';
export { useHttpErrorToast } from './use-http-error-toast';
export {
  IDENTITY_QUERY_KEY,
  useCanEditAttributeGroup,
  useCanEditChannel,
  useCanEditLocale,
  useCanI,
  useCanIAll,
  useCanIAny,
  useIdentity,
} from './use-identity';

export { usePermissionInvalidationSse } from './use-permission-invalidation-sse';
