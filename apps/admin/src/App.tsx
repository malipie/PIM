import { Refine } from '@refinedev/core';
import { type ComponentType, type LazyExoticComponent, lazy, Suspense } from 'react';
import { BrowserRouter, Navigate, Route, Routes, useParams } from 'react-router';

import { AuthedRoute } from '@/components/AuthedRoute';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { PermissionRoute } from '@/components/identity';
import { ToastProvider } from '@/components/ui/toast';
import { FirstLoginChangePasswordPage } from '@/features/auth/FirstLoginChangePasswordPage';
// HARD-08 — only the login + dashboard pages and the always-mounted
// layout shells stay eager. Every other route is React.lazy()-ed so
// the initial bundle drops from ~2.1 MB to roughly the shell + the
// landing screen. Each route gets its own chunk; revisits hit the
// HTTP cache after the first lazy load.
import { DashboardPage } from '@/features/dashboard/page';
import { AcceptInvitationPage } from '@/features/identity/accept-invitation/AcceptInvitationPage';
import { LoginPage } from '@/features/identity/auth/login';
import { ForgotPasswordPage } from '@/features/identity/forgot-password/ForgotPasswordPage';
import { PasswordResetPage } from '@/features/identity/password-reset/PasswordResetPage';
import { AppLayout } from '@/layout/AppLayout';
import { SettingsLayout } from '@/layout/SettingsLayout';
import { authProvider } from '@/lib/auth-provider';
import { dataProvider } from '@/lib/data-provider';
import { AGENT_ENABLED } from '@/lib/features';
import { notificationProvider } from '@/lib/refine-notification-provider';

/**
 * Lazy-loads a page module that exports the component as a named
 * binding (`export function FooPage`). React.lazy itself only
 * accepts default exports, so we adapt with a tiny mapping step.
 */
// The constraint uses ComponentType<never> rather than <unknown> so page
// components that accept optional props (e.g. the api-configurator screens with
// an `embedded?` flag, rendered prop-less by the router) still satisfy it.
function lazyPage<K extends string, T extends Record<K, ComponentType<never>>>(
  loader: () => Promise<T>,
  exportName: K,
): LazyExoticComponent<ComponentType<unknown>> {
  return lazy(() => loader().then((m) => ({ default: m[exportName] as ComponentType<unknown> })));
}

/** #2574 — preserve the definition id when redirecting the old settings path. */
function WorkflowDefinitionEditRedirect(): React.ReactElement {
  const { id } = useParams<{ id: string }>();
  return <Navigate to={`/workflow/definitions/${id}/edit`} replace />;
}

const AgentInboxPage = lazyPage(
  () => import('@/features/agent/inbox/AgentInboxPage'),
  'AgentInboxPage',
);
const AgentHistoryPage = lazyPage(
  () => import('@/features/agent/history/AgentHistoryPage'),
  'AgentHistoryPage',
);
const ApiProfileCreatePage = lazyPage(
  () => import('@/features/api-configurator/api-profiles/create'),
  'ApiProfileCreatePage',
);
const ApiProfileEditPage = lazyPage(
  () => import('@/features/api-configurator/api-profiles/edit'),
  'ApiProfileEditPage',
);
const ProducerHubPage = lazyPage(
  () => import('@/features/api-configurator/producer/ProducerHubPage'),
  'ProducerHubPage',
);
const ProfileBuilderPage = lazyPage(
  () => import('@/features/api-configurator/producer/profile-builder/ProfileBuilderPage'),
  'ProfileBuilderPage',
);
const ApiProfileShowPage = lazyPage(
  () => import('@/features/api-configurator/api-profiles/show'),
  'ApiProfileShowPage',
);
const KonfiguratorApiLayout = lazyPage(
  () => import('@/features/api-configurator/layout/KonfiguratorApiLayout'),
  'KonfiguratorApiLayout',
);
const ConnectionsHubPage = lazyPage(
  () => import('@/features/api-configurator/consumer/ConnectionsHubPage'),
  'ConnectionsHubPage',
);
const ConnectionWizardPage = lazyPage(
  () => import('@/features/api-configurator/consumer/wizard/ConnectionWizardPage'),
  'ConnectionWizardPage',
);
const MappingScreen = lazyPage(
  () => import('@/features/api-configurator/consumer/mapping/MappingScreen'),
  'MappingScreen',
);
const SyncConfigScreen = lazyPage(
  () => import('@/features/api-configurator/consumer/sync/SyncConfigScreen'),
  'SyncConfigScreen',
);
const ConnectionDetailPage = lazyPage(
  () => import('@/features/api-configurator/consumer/detail/ConnectionDetailPage'),
  'ConnectionDetailPage',
);
const SyncMonitorScreen = lazyPage(
  () => import('@/features/api-configurator/monitor/SyncMonitorScreen'),
  'SyncMonitorScreen',
);
const FeedsHubPage = lazyPage(
  () => import('@/features/api-configurator/feeds/hub/FeedsHubPage'),
  'FeedsHubPage',
);
const FeedWizardPage = lazyPage(
  () => import('@/features/api-configurator/feeds/wizard/FeedWizardPage'),
  'FeedWizardPage',
);
const FeedDetailPage = lazyPage(
  () => import('@/features/api-configurator/feeds/detail/FeedDetailPage'),
  'FeedDetailPage',
);
const FeedsMonitorPage = lazyPage(
  () => import('@/features/api-configurator/feeds/monitor/FeedsMonitorPage'),
  'FeedsMonitorPage',
);
const AssetsListPage = lazyPage(() => import('@/features/asset/assets/list'), 'AssetsListPage');
const AssetShowPage = lazyPage(() => import('@/features/asset/assets/show'), 'AssetShowPage');
const AttributeGroupCreatePage = lazyPage(
  () => import('@/features/catalog/attribute-groups/create'),
  'AttributeGroupCreatePage',
);
const AttributeGroupsListPage = lazyPage(
  () => import('@/features/catalog/attribute-groups/list'),
  'AttributeGroupsListPage',
);
const AttributeGroupShowPage = lazyPage(
  () => import('@/features/catalog/attribute-groups/show'),
  'AttributeGroupShowPage',
);
const AttributesListPage = lazyPage(
  () => import('@/features/catalog/attributes/list'),
  'AttributesListPage',
);
const MigrateAttributeTypePage = lazyPage(
  () => import('@/features/catalog/attributes/migrate-type'),
  'MigrateAttributeTypePage',
);
const AttributeCreatePage = lazyPage(
  () => import('@/features/catalog/attributes/new'),
  'AttributeCreatePage',
);
const AttributeShowPage = lazyPage(
  () => import('@/features/catalog/attributes/show'),
  'AttributeShowPage',
);
const AttributeValuesPage = lazyPage(
  () => import('@/features/catalog/attributes/values'),
  'AttributeValuesPage',
);
const CategoriesTreePage = lazyPage(
  () => import('@/features/catalog/categories/list'),
  'CategoriesTreePage',
);
const CategoryCreatePage = lazyPage(
  () => import('@/features/catalog/categories/new'),
  'CategoryCreatePage',
);
const CategoryShowPage = lazyPage(
  () => import('@/features/catalog/categories/show'),
  'CategoryShowPage',
);
const ModelingLayout = lazyPage(
  () => import('@/features/catalog/modeling/layout'),
  'ModelingLayout',
);
const ObjectTypesListPage = lazyPage(
  () => import('@/features/catalog/object-types/list'),
  'ObjectTypesListPage',
);
const ObjectTypeWizardPage = lazyPage(
  () => import('@/features/catalog/object-types/new'),
  'ObjectTypeWizardPage',
);
const ObjectTypeShowPage = lazyPage(
  () => import('@/features/catalog/object-types/show'),
  'ObjectTypeShowPage',
);
const UniversalObjectListPage = lazyPage(
  () => import('@/features/catalog/objects/list-page'),
  'ObjectListPage',
);
const UniversalObjectShowPage = lazyPage(
  () => import('@/features/catalog/objects/show-page'),
  'ObjectShowPage',
);
const UniversalObjectCreatePage = lazyPage(
  () => import('@/features/catalog/objects/create-page'),
  'ObjectCreatePage',
);
const ProductCreatePage = lazyPage(
  () => import('@/features/catalog/products/create'),
  'ProductCreatePage',
);
const ProductsUniversalListPage = lazyPage(
  () => import('@/features/catalog/products/universal-list'),
  'ProductsUniversalListPage',
);
const ProductShowPage = lazyPage(
  () => import('@/features/catalog/products/show'),
  'ProductShowPage',
);
const CatalogsPdfPage = lazyPage(() => import('@/features/catalogs-pdf'), 'CatalogsPdfPage');
const WorkflowPage = lazyPage(() => import('@/features/workflow/WorkflowPage'), 'WorkflowPage');
const CatalogWizardPage = lazyPage(
  () => import('@/features/catalogs-pdf/wizard/CatalogWizardPage'),
  'CatalogWizardPage',
);
const ChannelCreatePage = lazyPage(
  () => import('@/features/channel/channels/create'),
  'ChannelCreatePage',
);
const ChannelEditPage = lazyPage(
  () => import('@/features/channel/channels/edit'),
  'ChannelEditPage',
);
const ChannelsListPage = lazyPage(
  () => import('@/features/channel/channels/list'),
  'ChannelsListPage',
);
const ChannelShowPage = lazyPage(
  () => import('@/features/channel/channels/show'),
  'ChannelShowPage',
);
const ImportsLayout = lazyPage(
  () => import('@/features/imports/layout/ImportsLayout'),
  'ImportsLayout',
);
const ImportProfilesView = lazyPage(
  () => import('@/features/imports/profiles/ImportProfilesView'),
  'ImportProfilesView',
);
const ImportScheduleView = lazyPage(
  () => import('@/features/imports/schedule/ImportScheduleView'),
  'ImportScheduleView',
);
const ImportSessionsView = lazyPage(
  () => import('@/features/imports/sessions/ImportSessionsView'),
  'ImportSessionsView',
);
const ImportShowPage = lazyPage(
  () => import('@/features/imports/show/ImportShowPage'),
  'ImportShowPage',
);
const ImportSourcesView = lazyPage(
  () => import('@/features/imports/sources/ImportSourcesView'),
  'ImportSourcesView',
);
const ImportWizardPage = lazyPage(
  () => import('@/features/imports/wizard/ImportWizardPage'),
  'ImportWizardPage',
);
// EXP-09 (#588) — Exports hub MVP. Placeholder views for sessions/profiles
// + new flow; real grids land with EXP-13/EXP-14, column picker EXP-10.
const ExportsLayout = lazyPage(
  () => import('@/features/exports/layout/ExportsLayout'),
  'ExportsLayout',
);
const ExportSessionsView = lazyPage(
  () => import('@/features/exports/sessions/ExportSessionsView'),
  'ExportSessionsView',
);
const ExportProfilesView = lazyPage(
  () => import('@/features/exports/profiles/ExportProfilesView'),
  'ExportProfilesView',
);
const ExportSessionShowPage = lazyPage(
  () => import('@/features/exports/show/ExportSessionShowPage'),
  'ExportSessionShowPage',
);
const ExportWizardPage = lazyPage(
  () => import('@/features/exports/wizard/ExportWizardPage'),
  'ExportWizardPage',
);
const AiSettingsPage = lazyPage(() => import('@/features/settings/ai'), 'AiSettingsPage');
const BillingSettingsPage = lazyPage(
  () => import('@/features/settings/billing'),
  'BillingSettingsPage',
);
const LocalesSettingsPage = lazyPage(
  () => import('@/features/settings/locales'),
  'LocalesSettingsPage',
);
const MenuSettingsPage = lazyPage(() => import('@/features/settings/menu'), 'MenuSettingsPage');
const RolesSettingsPage = lazyPage(() => import('@/features/settings/roles'), 'RolesSettingsPage');
const RolesEditorRoute = lazyPage(() => import('@/features/settings/roles'), 'RolesEditorRoute');
const SsoSettingsPage = lazyPage(() => import('@/features/settings/sso'), 'SsoSettingsPage');
const WorkflowSettingsPage = lazyPage(
  () => import('@/features/settings/workflow'),
  'WorkflowSettingsPage',
);
const WorkflowDefinitionEditorRoute = lazyPage(
  () => import('@/features/settings/workflow'),
  'WorkflowDefinitionEditorRoute',
);
const SettingsIndex = lazyPage(() => import('@/features/settings/SettingsIndex'), 'SettingsIndex');
const SecuritySettingsPage = lazyPage(
  () => import('@/features/settings/security'),
  'SecuritySettingsPage',
);
const UsersSettingsPage = lazyPage(() => import('@/features/settings/users'), 'UsersSettingsPage');
const UserDetailRoute = lazyPage(() => import('@/features/settings/users'), 'UserDetailRoute');
const ApiTokensSettingsPage = lazyPage(
  () => import('@/features/settings/api-tokens'),
  'ApiTokensSettingsPage',
);
const TenantSettingsPage = lazyPage(
  () => import('@/features/settings/tenant'),
  'TenantSettingsPage',
);
const AdminTenantsListPage = lazyPage(
  () => import('@/features/admin/tenants'),
  'AdminTenantsListPage',
);
const AdminTenantShowPage = lazyPage(
  () => import('@/features/admin/tenants'),
  'AdminTenantShowPage',
);
const AdminBreakGlassPage = lazyPage(
  () => import('@/features/admin/break-glass'),
  'AdminBreakGlassPage',
);

/**
 * Suspense fallback shown while a route chunk loads. Discreet — the
 * lazy load typically resolves in 50–150 ms on a warm cache; a full
 * spinner would feel jankier than the brief blank slot.
 */
function RouteFallback() {
  return (
    <div
      className="flex h-full min-h-[200px] items-center justify-center text-sm text-muted-foreground"
      role="status"
      aria-live="polite"
    >
      <span className="size-2 animate-pulse rounded-full bg-muted-foreground/40" />
    </div>
  );
}

function App() {
  return (
    <BrowserRouter>
      <ToastProvider>
        <Refine
          authProvider={authProvider}
          dataProvider={dataProvider}
          notificationProvider={notificationProvider}
          resources={[
            {
              name: 'products',
              list: '/products',
              create: '/products/new',
              edit: '/products/:id/edit',
              show: '/products/:id',
            },
            {
              name: 'attributes',
              list: '/modeling/attributes',
              create: '/modeling/attributes/new',
              show: '/modeling/attributes/:id',
            },
            {
              name: 'attribute_groups',
              list: '/modeling/attribute-groups',
              create: '/modeling/attribute-groups/new',
              show: '/modeling/attribute-groups/:id',
            },
            {
              name: 'object_types',
              list: '/modeling/object-types',
              create: '/modeling/object-types/new',
              show: '/modeling/object-types/:id',
            },
            {
              name: 'workspaces',
            },
            {
              name: 'categories',
              list: '/modeling/categories',
              create: '/modeling/categories/new',
              show: '/modeling/categories/:id',
            },
            { name: 'assets', list: '/assets', show: '/assets/:id' },
            {
              name: 'channels',
              list: '/modeling/channels',
              create: '/modeling/channels/new',
              edit: '/modeling/channels/:id/edit',
              show: '/modeling/channels/:id',
            },
            { name: 'locales' },
            {
              name: 'api_profiles',
              list: '/integrations/api-configurator',
              create: '/integrations/api-configurator/create',
              edit: '/integrations/api-configurator/:id/edit',
              show: '/integrations/api-configurator/:id',
            },
            {
              // APIC-P1-07 — consumer connections (GET /api/connections).
              name: 'connections',
              list: '/integrations/api-configurator/connections',
              edit: '/integrations/api-configurator/connections/:id/edit',
            },
            {
              // RBAC-P5-001 (#691) — Settings → Users list. The page lives at
              // /settings/users; Refine resource registration drives the
              // useList wiring + future create/edit routes (#692/#693).
              name: 'users',
              list: '/settings/users',
            },
            {
              // RBAC-P5-005 (#695) — Settings → Roles list. Lists system
              // templates + tenant custom roles with user counts. Create/edit
              // routes land with #696 (custom role builder).
              name: 'roles',
              list: '/settings/roles',
            },
            {
              // RBAC-P5-009 (#699) — Settings → API tokens list. Endpoint
              // is `/api/api-tokens`; Refine dataProvider hits it via the
              // resource name. Create/revoke routes land with #700/#701.
              name: 'api-tokens',
              list: '/settings/api-tokens',
            },
            {
              name: 'import-sessions',
              list: '/integrations/imports/sessions',
              show: '/integrations/imports/:id',
              create: '/integrations/imports/new',
            },
            {
              name: 'import-profiles',
              list: '/integrations/imports/profiles',
            },
            {
              name: 'import-sources',
              list: '/integrations/imports/sources',
            },
            {
              name: 'import-schedules',
              list: '/integrations/imports/schedule',
            },
            // EXP-09 (#588) — Refine resource registrations for the
            // Exports hub. List endpoints land with EXP-13 (sessions
            // grid) and EXP-14 (profiles grid).
            {
              name: 'export-sessions',
              list: '/integrations/exports/sessions',
              show: '/integrations/exports/sessions/:id',
              create: '/integrations/exports/new',
            },
            {
              name: 'export-profiles',
              list: '/integrations/exports/profiles',
            },
          ]}
          options={{
            syncWithLocation: false,
            warnWhenUnsavedChanges: true,
            disableTelemetry: true,
          }}
        >
          {/* AUD-049 (W2-12) — top-level boundary so an uncaught render
              error in any route renders a recoverable fallback instead of
              blanking #root (white-screen incident 2026-05-13). Sits inside
              Refine + ToastProvider so the fallback keeps i18n context. */}
          <ErrorBoundary>
            <Suspense fallback={<RouteFallback />}>
              <Routes>
                <Route path="/login" element={<LoginPage />} />
                <Route path="/accept-invitation" element={<AcceptInvitationPage />} />
                {/* #2827 — both recovery mails link here. These must stay
                    OUTSIDE <AuthedRoute>: the whole point is that the caller
                    has no session, and the catch-all route below would send
                    them to /dashboard and on to /login, which is exactly the
                    dead-link symptom this ticket fixes. */}
                <Route path="/forgot-password" element={<ForgotPasswordPage />} />
                <Route path="/password-reset" element={<PasswordResetPage />} />
                {/* Manual user creation (#867) — force-password-change gate.
                  Sits inside <AuthedRoute> (JWT required to call
                  /api/me/change-password) but outside <AppLayout> so the
                  page renders as a standalone centred card, no sidebar.
                  AuthedRoute's redirect skips this path so the loop is
                  broken (see `FIRST_LOGIN_PATH` constant there). */}
                <Route
                  path="/first-login-password"
                  element={
                    <AuthedRoute>
                      <FirstLoginChangePasswordPage />
                    </AuthedRoute>
                  }
                />
                <Route
                  element={
                    <AuthedRoute>
                      <AppLayout />
                    </AuthedRoute>
                  }
                >
                  <Route index element={<Navigate to="/dashboard" replace />} />
                  <Route path="/dashboard" element={<DashboardPage />} />
                  {AGENT_ENABLED && <Route path="/agent/inbox" element={<AgentInboxPage />} />}
                  {AGENT_ENABLED && <Route path="/agent/history" element={<AgentHistoryPage />} />}
                  {/* UP-10 (#1026) — `/products` default is the
                    UniversalListPage parametrized for the built-in
                    product ObjectType (ADR-009 pixel-perfect parity
                    with /objects/:slug). NUI-05 (#1424) retired the
                    legacy ProductListPage after the dual-maintenance
                    window — `/products/legacy` only redirects now. */}
                  <Route path="/products" element={<ProductsUniversalListPage />} />
                  <Route path="/products/legacy" element={<Navigate to="/products" replace />} />
                  <Route path="/products/new" element={<ProductCreatePage />} />
                  {/* ULV-08 (#990) — universal /objects/{slug} route renders
                    the ObjectListView for any ObjectType by code. The
                    legacy /products / /categories / /assets routes keep
                    pointing at their own pages until ULV-11 cuts over. */}
                  <Route path="/objects/:slug" element={<UniversalObjectListPage />} />
                  {/* UP-08 (#1029) — universal /objects/:slug/new create route.
                    Built-in product/category/asset slugs redirect to their
                    legacy create routes inside ObjectCreatePage; custom kinds
                    render the unified create form (#1415). */}
                  <Route path="/objects/:slug/new" element={<UniversalObjectCreatePage />} />
                  {/* UP-07 (#1023) — universal /objects/:slug/:id detail route.
                    Built-in product/category/asset slugs redirect to their
                    legacy detail routes inside ObjectShowPage; custom kinds
                    render the unified ProductDetailPage (#1348/#1351). */}
                  <Route path="/objects/:slug/:id" element={<UniversalObjectShowPage />} />
                  {/* VIEW-07 (#420): edit page is now inline-edit on /products/:id.
                    Keep the legacy path as a back-compat redirect for bookmarks
                    and Refine resource lookups that still ask for `edit`. */}
                  <Route path="/products/:id/edit" element={<RedirectToShow />} />
                  <Route path="/products/:id" element={<ProductShowPage />} />
                  {/* UI-08.9 (#264) — Modeling shell wraps the 4 sub-tabs under
                  a shared `/modeling/*` route tree. Old top-level paths
                  redirect below for back-compat with bookmarks + the
                  Refine resource registry pointing at the new URLs. */}
                  <Route path="/modeling" element={<ModelingLayout />}>
                    <Route index element={<Navigate to="/modeling/object-types" replace />} />
                    <Route path="object-types" element={<ObjectTypesListPage />} />
                    <Route path="object-types/new" element={<ObjectTypeWizardPage />} />
                    <Route path="object-types/:id" element={<ObjectTypeShowPage />} />
                    <Route path="attributes" element={<AttributesListPage />} />
                    <Route path="attributes/new" element={<AttributeCreatePage />} />
                    <Route path="attributes/:id" element={<AttributeShowPage />} />
                    <Route
                      path="attributes/:id/migrate-type"
                      element={<MigrateAttributeTypePage />}
                    />
                    <Route path="attributes/:id/values" element={<AttributeValuesPage />} />
                    <Route path="attribute-groups" element={<AttributeGroupsListPage />} />
                    <Route path="attribute-groups/new" element={<AttributeGroupCreatePage />} />
                    <Route path="attribute-groups/:id" element={<AttributeGroupShowPage />} />
                    <Route path="categories" element={<CategoriesTreePage />} />
                    <Route path="categories/new" element={<CategoryCreatePage />} />
                    <Route path="categories/:id" element={<CategoryShowPage />} />
                    {/* DP-03 (#2033) — Channels + Locales moved in from Settings:
                      they scope attribute values (scopable/localizable), so they
                      are data-model configuration. Old /settings/* paths redirect
                      below. Backend permissions still gate every endpoint. */}
                    <Route path="channels" element={<ChannelsListPage />} />
                    <Route path="channels/new" element={<ChannelCreatePage />} />
                    <Route path="channels/:id" element={<ChannelShowPage />} />
                    <Route path="channels/:id/edit" element={<ChannelEditPage />} />
                    <Route path="locales" element={<LocalesSettingsPage />} />
                  </Route>
                  <Route
                    path="/attributes"
                    element={<Navigate to="/modeling/attributes" replace />}
                  />
                  <Route
                    path="/attributes/:id"
                    element={<Navigate to="/modeling/attributes" replace />}
                  />
                  <Route
                    path="/attribute-groups"
                    element={<Navigate to="/modeling/attribute-groups" replace />}
                  />
                  <Route
                    path="/object-types"
                    element={<Navigate to="/modeling/object-types" replace />}
                  />
                  <Route
                    path="/object-types/:id"
                    element={<Navigate to="/modeling/object-types" replace />}
                  />
                  <Route
                    path="/categories"
                    element={<Navigate to="/modeling/categories" replace />}
                  />
                  <Route
                    path="/categories/:id"
                    element={<Navigate to="/modeling/categories" replace />}
                  />
                  <Route path="/assets" element={<AssetsListPage />} />
                  <Route path="/assets/:id" element={<AssetShowPage />} />
                  {/* CPDF-P5-05 (#2303) — gate the Catalog PDF area behind the
                    reused exports read permission (ADR-0027, no dedicated
                    catalogs_pdf module). A user without exports scope who
                    deep-links here lands on Forbidden403Page instead of the
                    shell; per-action writes stay gated by their own BE checks
                    (integration.admin). Mirrors MENU_PERMISSIONS.catalogs_pdf. */}
                  {/* WFL-P3-02 (#2424) — review queue; mirror of
                      MENU_PERMISSIONS.workflow (workflow.view). */}
                  <Route
                    path="/workflow"
                    element={
                      <PermissionRoute anyOf={['workflow.view']}>
                        <WorkflowPage />
                      </PermissionRoute>
                    }
                  />
                  {/* #3000 — flow configuration lives in ONE screen now.
                      The old settings route stays as a redirect: it is
                      linked from notification e-mails and operator
                      bookmarks. */}
                  <Route
                    path="/workflow/settings"
                    element={<Navigate to="/workflow/definitions" replace />}
                  />
                  {/* #2574 — custom workflow definitions moved out of Settings
                      into the Workflow hub. Old /settings/workflow* paths
                      redirect below. */}
                  <Route
                    path="/workflow/definitions"
                    element={
                      <PermissionRoute anyOf={['workflow.manage_definitions']}>
                        <WorkflowSettingsPage />
                      </PermissionRoute>
                    }
                  />
                  <Route
                    path="/workflow/definitions/new"
                    element={
                      <PermissionRoute anyOf={['workflow.manage_definitions']}>
                        <WorkflowDefinitionEditorRoute />
                      </PermissionRoute>
                    }
                  />
                  <Route
                    path="/workflow/definitions/:id/edit"
                    element={
                      <PermissionRoute anyOf={['workflow.manage_definitions']}>
                        <WorkflowDefinitionEditorRoute />
                      </PermissionRoute>
                    }
                  />
                  <Route
                    path="/catalogs-pdf"
                    element={
                      <PermissionRoute anyOf={['exports.view_own', 'exports.view_all']}>
                        <CatalogsPdfPage />
                      </PermissionRoute>
                    }
                  />
                  <Route
                    path="/catalogs-pdf/new"
                    element={
                      <PermissionRoute anyOf={['exports.view_own', 'exports.view_all']}>
                        <CatalogWizardPage />
                      </PermissionRoute>
                    }
                  />
                  {/* #2566 — edit an existing catalog (wizard prefilled). */}
                  <Route
                    path="/catalogs-pdf/:id/edit"
                    element={
                      <PermissionRoute anyOf={['exports.view_own', 'exports.view_all']}>
                        <CatalogWizardPage />
                      </PermissionRoute>
                    }
                  />
                  {/* AUD-076 (W3-5.5) — gate the whole settings tree with the
                    same permission set the sidebar uses to show the entry
                    (MENU_PERMISSIONS.settings). A user with no settings
                    permission who deep-links /settings/* now lands on the
                    Forbidden403Page instead of the settings shell. Per-page
                    write actions stay gated by their own backend checks. */}
                  <Route
                    path="/settings"
                    element={
                      <PermissionRoute
                        anyOf={[
                          'settings.users.manage',
                          'settings.roles.manage',
                          'settings.tenant.manage',
                          'settings.integrations.manage',
                          'settings.billing.manage',
                          'workflow.manage_definitions',
                        ]}
                      >
                        <SettingsLayout />
                      </PermissionRoute>
                    }
                  >
                    <Route index element={<SettingsIndex />} />
                    <Route path="menu" element={<MenuSettingsPage />} />
                    {/* DP-03 (#2033) — back-compat redirects: Channels + Locales
                      live under /modeling now; bookmarks keep working. */}
                    <Route path="locales" element={<Navigate to="/modeling/locales" replace />} />
                    <Route path="channels" element={<Navigate to="/modeling/channels" replace />} />
                    <Route
                      path="channels/new"
                      element={<Navigate to="/modeling/channels/new" replace />}
                    />
                    <Route path="channels/:id" element={<RedirectSettingsChannelShow />} />
                    <Route path="channels/:id/edit" element={<RedirectSettingsChannelEdit />} />
                    <Route path="users" element={<UsersSettingsPage />} />
                    <Route path="users/:id" element={<UserDetailRoute />} />
                    <Route path="roles" element={<RolesSettingsPage />} />
                    <Route path="roles/new" element={<RolesEditorRoute />} />
                    <Route path="roles/:id/edit" element={<RolesEditorRoute />} />
                    <Route path="api-tokens" element={<ApiTokensSettingsPage />} />
                    <Route path="security" element={<SecuritySettingsPage />} />
                    <Route path="billing" element={<BillingSettingsPage />} />
                    <Route path="tenant" element={<TenantSettingsPage />} />
                    <Route path="ai" element={<AiSettingsPage />} />
                    <Route path="sso" element={<SsoSettingsPage />} />
                    {/* #2574 — the definition builder moved to /workflow/
                      definitions*; keep the old settings paths as redirects. */}
                    <Route
                      path="workflow"
                      element={<Navigate to="/workflow/definitions" replace />}
                    />
                    <Route
                      path="workflow/new"
                      element={<Navigate to="/workflow/definitions/new" replace />}
                    />
                    <Route path="workflow/:id/edit" element={<WorkflowDefinitionEditRedirect />} />
                  </Route>
                  {/* RBAC-P5-019 (#709) — Super Admin operator panel.
                    Lives under /admin/* inside the existing app until
                    the admin.cortex.pl subdomain split (operator infra
                    task). Backend already enforces super_admin role +
                    cross-tenant bypass via SuperAdminContext.

                    AUD-076 (W3-5.5) — wrap each page in <PermissionRoute> so
                    an Owner/Admin who types the URL gets the Forbidden403Page
                    instead of the panel mounting and flashing its UI before
                    the async 403 from /api/admin/* flips it to a forbidden
                    state. The codes mirror the backend #[RequiresPermission]
                    on SuperAdminTenantsController (platform.tenants.manage)
                    and BreakGlassController (platform.break_glass_recovery). */}
                  <Route
                    path="/admin/tenants"
                    element={
                      <PermissionRoute code="platform.tenants.manage">
                        <AdminTenantsListPage />
                      </PermissionRoute>
                    }
                  />
                  <Route
                    path="/admin/tenants/:id"
                    element={
                      <PermissionRoute code="platform.tenants.manage">
                        <AdminTenantShowPage />
                      </PermissionRoute>
                    }
                  />
                  <Route
                    path="/admin/break-glass"
                    element={
                      <PermissionRoute code="platform.break_glass_recovery">
                        <AdminBreakGlassPage />
                      </PermissionRoute>
                    }
                  />
                  {/* Integracje — every hub (exports EXR-08, imports NUI-09,
                    api-configurator) renders directly under the v2 shell;
                    sidebar second-level + topbar breadcrumb replaced the old
                    in-page Integrations header/tabs. Old /publications/* and
                    /api-profiles/* paths redirect below. */}
                  <Route path="/integrations/exports" element={<ExportsLayout />}>
                    <Route index element={<Navigate to="sessions" replace />} />
                    <Route path="sessions" element={<ExportSessionsView />} />
                    <Route path="profiles" element={<ExportProfilesView />} />
                  </Route>
                  <Route
                    path="/integrations/exports/sessions/:id"
                    element={<ExportSessionShowPage />}
                  />
                  <Route path="/integrations/exports/new" element={<ExportWizardPage />} />
                  {/* NUI-09 (#1428) — Imports join Exports directly under the
                    v2 shell; the legacy IntegrationsLayout wrapper is retired.
                    Wizard + show pages live at the same depth so deep-links
                    from emails/reports survive (VIEW-IMP-00 #493). */}
                  <Route path="/integrations/imports" element={<ImportsLayout />}>
                    <Route index element={<Navigate to="sessions" replace />} />
                    <Route path="sessions" element={<ImportSessionsView />} />
                    <Route path="profiles" element={<ImportProfilesView />} />
                    <Route path="sources" element={<ImportSourcesView />} />
                    <Route path="schedule" element={<ImportScheduleView />} />
                  </Route>
                  <Route path="/integrations/imports/new" element={<ImportWizardPage />} />
                  <Route path="/integrations/imports/:id" element={<ImportShowPage />} />
                  <Route element={<KonfiguratorApiLayout />}>
                    {/* #2576 — the configurator opens on the Połączenia
                        (connections) tab; the producer hub gets its own path. */}
                    <Route
                      path="/integrations/api-configurator"
                      element={<Navigate to="/integrations/api-configurator/connections" replace />}
                    />
                    <Route
                      path="/integrations/api-configurator/producer"
                      element={<ProducerHubPage />}
                    />
                    <Route
                      path="/integrations/api-configurator/create"
                      element={<ApiProfileCreatePage />}
                    />
                    <Route
                      path="/integrations/api-configurator/profiles/new"
                      element={<ProfileBuilderPage />}
                    />
                    <Route
                      path="/integrations/api-configurator/profiles/:id/edit"
                      element={<ProfileBuilderPage />}
                    />
                    <Route
                      path="/integrations/api-configurator/connections"
                      element={<ConnectionsHubPage />}
                    />
                    <Route
                      path="/integrations/api-configurator/connections/new"
                      element={<ConnectionWizardPage />}
                    />
                    {/* #2630 — the same wizard reused in edit mode. */}
                    <Route
                      path="/integrations/api-configurator/connections/:id/edit"
                      element={<ConnectionWizardPage />}
                    />
                    <Route
                      path="/integrations/api-configurator/connections/:id/mapping"
                      element={<MappingScreen />}
                    />
                    <Route
                      path="/integrations/api-configurator/connections/:id/sync"
                      element={<SyncConfigScreen />}
                    />
                    <Route
                      path="/integrations/api-configurator/connections/:id"
                      element={<ConnectionDetailPage />}
                    />
                    <Route
                      path="/integrations/api-configurator/monitor"
                      element={<SyncMonitorScreen />}
                    />
                    <Route
                      path="/integrations/api-configurator/:id/edit"
                      element={<ApiProfileEditPage />}
                    />
                    <Route
                      path="/integrations/api-configurator/:id"
                      element={<ApiProfileShowPage />}
                    />
                  </Route>
                  {/* XMLF — product XML feeds (hub / monitor / wizard / detail).
                      Shipped as a tab of the Konfigurator API shell, promoted
                      to its own sidebar entry in XMLF-FUP-01 (#2028): same
                      URLs, rendered outside the shell (FeedsSubnav is the
                      area's own hub|monitor navigation). */}
                  <Route path="/integrations/api-configurator/feeds" element={<FeedsHubPage />} />
                  <Route
                    path="/integrations/api-configurator/feeds/monitor"
                    element={<FeedsMonitorPage />}
                  />
                  <Route
                    path="/integrations/api-configurator/feeds/new"
                    element={<FeedWizardPage />}
                  />
                  <Route
                    path="/integrations/api-configurator/feeds/:id/edit"
                    element={<FeedWizardPage />}
                  />
                  <Route
                    path="/integrations/api-configurator/feeds/:id"
                    element={<FeedDetailPage />}
                  />
                  <Route
                    path="/integrations"
                    element={<Navigate to="/integrations/imports/sessions" replace />}
                  />
                  {/* Back-compat redirects for bookmarks + Refine resource lookups
                    before the consolidation landed. Drop in next epik gdy
                    telemetria pokaże 0 trafień. */}
                  <Route
                    path="/publications"
                    element={<Navigate to="/integrations/imports/sessions" replace />}
                  />
                  <Route
                    path="/publications/imports"
                    element={<Navigate to="/integrations/imports/sessions" replace />}
                  />
                  <Route
                    path="/publications/imports/new"
                    element={<Navigate to="/integrations/imports/new" replace />}
                  />
                  <Route path="/publications/imports/:id" element={<RedirectImportShow />} />
                  <Route
                    path="/api-profiles"
                    element={<Navigate to="/integrations/api-configurator" replace />}
                  />
                  <Route
                    path="/api-profiles/create"
                    element={<Navigate to="/integrations/api-configurator/create" replace />}
                  />
                  <Route path="/api-profiles/:id" element={<RedirectApiProfileShow />} />
                  <Route path="/api-profiles/:id/edit" element={<RedirectApiProfileEdit />} />
                </Route>
                <Route path="*" element={<Navigate to="/dashboard" replace />} />
              </Routes>
            </Suspense>
          </ErrorBoundary>
        </Refine>
      </ToastProvider>
    </BrowserRouter>
  );
}

/** VIEW-07 (#420) — back-compat redirect for `/products/:id/edit`. */
function RedirectToShow() {
  const params = useParams<{ id: string }>();
  return <Navigate to={`/products/${params.id ?? ''}`} replace />;
}

/** Publications/Integrations consolidation — back-compat for the old
 *  /publications/imports/:id, /api-profiles/:id, and /api-profiles/:id/edit
 *  bookmarks. */
function RedirectImportShow() {
  const params = useParams<{ id: string }>();
  return <Navigate to={`/integrations/imports/${params.id ?? ''}`} replace />;
}

function RedirectApiProfileShow() {
  const params = useParams<{ id: string }>();
  return <Navigate to={`/integrations/api-configurator/${params.id ?? ''}`} replace />;
}

function RedirectApiProfileEdit() {
  const params = useParams<{ id: string }>();
  return <Navigate to={`/integrations/api-configurator/${params.id ?? ''}/edit`} replace />;
}

/** DP-03 (#2033) — back-compat redirects for the old /settings/channels/:id
 *  bookmarks after the move to /modeling/channels. */
function RedirectSettingsChannelShow() {
  const params = useParams<{ id: string }>();
  return <Navigate to={`/modeling/channels/${params.id ?? ''}`} replace />;
}

function RedirectSettingsChannelEdit() {
  const params = useParams<{ id: string }>();
  return <Navigate to={`/modeling/channels/${params.id ?? ''}/edit`} replace />;
}

export default App;
