import { useTranslation } from 'react-i18next';
import { Outlet, useLocation } from 'react-router';

import { Forbidden403Page } from '@/components/identity';
import { hasAnyPermission, useIdentity } from '@/lib/identity';
import { requiredPermissionsForPath } from '@/lib/identity/route-permissions';

/**
 * #2830 — one gate over every routed page inside the app shell.
 *
 * Sits between `AppLayout` and the routed `<Outlet />`, so a URL the
 * caller has no permission for renders the 403 screen instead of the
 * page. Before this, only a handful of routes carried
 * `<PermissionRoute>`; the rest rendered fully and let the backend
 * refuse on submit — a Catalog Manager could fill in the whole
 * object-type wizard and only then meet HTTP 403.
 *
 * The permission map lives in `lib/identity/route-permissions.ts`, next
 * to the sidebar's `MENU_PERMISSIONS`, so hiding an entry and blocking
 * its URL are decided from the same place.
 *
 * While identity is loading we render the outlet rather than a spinner:
 * the check is a second line of defence (the API enforces the real one),
 * and flashing 403 on every hard reload would be worse than briefly
 * rendering a page that resolves to allowed a moment later.
 */
export function SectionPermissionGuard() {
  const { t } = useTranslation();
  const { identity, isLoading } = useIdentity();
  const { pathname } = useLocation();

  const required = requiredPermissionsForPath(pathname);

  if (null === required || isLoading || !identity) {
    return <Outlet />;
  }

  if (hasAnyPermission(identity, required)) {
    return <Outlet />;
  }

  return (
    <Forbidden403Page
      permissionCode={required.join(', ')}
      detailMessage={t('permissions.section_forbidden', {
        defaultValue: 'Ta sekcja wymaga uprawnień, których nie ma Twoja rola.',
      })}
    />
  );
}
