<?php

declare(strict_types=1);

namespace App\Identity\Domain\Rbac;

/**
 * RBAC-P1-007 (#646) — declarative source of truth for the 9 PRD-PIM-rbac §3.2
 * role templates: 1 platform-level (`super_admin`) + 8 tenant-level.
 *
 * Each tenant-level entry is the seed copied on tenant onboarding (Phase 2
 * listener); the platform-level entry is seeded once globally.
 *
 * Maintenance: when the macierz changes, edit this file. The
 * `App\Identity\Application\TenantOnboardingService` + the CLI command
 * `cortex:tenant:seed-roles` consume this map; a missing permission code
 * (typo, dropped from PrdPermissionFixtures) fails fast at seed time.
 */
final class PrdRoleTemplates
{
    /**
     * Map: role_code => human-readable name.
     *
     * @return array<string, string>
     */
    public static function tenantRoleNames(): array
    {
        return [
            'tenant_owner' => 'Tenant Owner',
            'admin' => 'Administrator',
            'catalog_manager' => 'Catalog Manager',
            'marketing' => 'Content Editor (Marketing)',
            'modeler' => 'Information Architect (Modeler)',
            'integration_manager' => 'Integration Manager',
            'channel_manager' => 'Channel Manager',
            'approver' => 'Approver',
            'viewer' => 'Viewer',
        ];
    }

    /**
     * Platform-level (`super_admin`) name.
     */
    public static function superAdminRoleName(): string
    {
        return 'Super Admin';
    }

    /**
     * Permissions granted to the platform-level Super Admin role.
     * Cross-tenant, immutable, seeded once globally.
     *
     * @return list<string>
     */
    public static function superAdminPermissions(): array
    {
        return [
            'platform.tenants.list',
            'platform.tenants.manage',
            'platform.audit.view_all',
            'platform.break_glass_recovery',
        ];
    }

    /**
     * Permission assignments per tenant-level role.
     *
     * @return array<string, list<string>>
     */
    public static function tenantRolePermissions(): array
    {
        return [
            // Tenant Owner — every tenant permission + tenant.delete
            'tenant_owner' => [
                'products.view', 'products.add', 'products.edit', 'products.delete',
                'products.bulk_operations', 'products.approve_pending_changes',
                'categories.view', 'categories.add_edit', 'categories.delete',
                'multimedia.view', 'multimedia.add_edit_own', 'multimedia.add_edit_any', 'multimedia.delete',
                'modeling.view', 'modeling.attributes.add_edit', 'modeling.attribute_groups.add_edit',
                'modeling.object_types.add', 'modeling.delete_custom', 'modeling.approve_schema_ops',
                'modeling.auto_grant_new_object_types',
                'publications.view', 'publications.publish_unpublish',
                'imports.view_own', 'imports.view_all', 'imports.run',
                'exports.view_own', 'exports.view_all', 'exports.run',
                'workflow.view', 'workflow.approve_reject', 'workflow.edit_any_state',
                'agent.schema_ops', 'agent.bulk_actions', 'agent.approve_pending',
                'settings.users.manage', 'settings.roles.manage', 'settings.tenant.manage',
                'settings.locales.manage',
                'settings.billing.manage', 'settings.integrations.manage', 'settings.integration_secrets.read',
                'settings.ai_content.read', 'settings.ai_content.create', 'settings.ai_content.admin',
                'api_tokens.own.crud', 'api_tokens.all.view_revoke',
                'audit.view_own', 'audit.view_cross_user',
                'tenant.delete',
                // ULV-04a (#985) — universal ObjectListView verbs (covers
                // every ObjectType, custom included).
                'object.view', 'object.add', 'object.edit', 'object.delete', 'object.export',
            ],

            // Administrator — everything except tenant.delete and billing
            'admin' => [
                'products.view', 'products.add', 'products.edit', 'products.delete',
                'products.bulk_operations', 'products.approve_pending_changes',
                'categories.view', 'categories.add_edit', 'categories.delete',
                'multimedia.view', 'multimedia.add_edit_own', 'multimedia.add_edit_any', 'multimedia.delete',
                'modeling.view', 'modeling.attributes.add_edit', 'modeling.attribute_groups.add_edit',
                'modeling.object_types.add', 'modeling.delete_custom', 'modeling.approve_schema_ops',
                'modeling.auto_grant_new_object_types',
                'publications.view', 'publications.publish_unpublish',
                'imports.view_own', 'imports.view_all', 'imports.run',
                'exports.view_own', 'exports.view_all', 'exports.run',
                'workflow.view', 'workflow.approve_reject', 'workflow.edit_any_state',
                'agent.schema_ops', 'agent.bulk_actions', 'agent.approve_pending',
                'settings.users.manage', 'settings.roles.manage', 'settings.tenant.manage',
                'settings.locales.manage',
                'settings.integrations.manage', 'settings.integration_secrets.read',
                'settings.ai_content.read', 'settings.ai_content.create', 'settings.ai_content.admin',
                'api_tokens.own.crud', 'api_tokens.all.view_revoke',
                'audit.view_own', 'audit.view_cross_user',
                // ULV-04a (#985) — universal ObjectListView verbs.
                'object.view', 'object.add', 'object.edit', 'object.delete', 'object.export',
            ],

            // Catalog Manager — products / categories / multimedia full CRUD + workflow approve + bulk agent
            'catalog_manager' => [
                'products.view', 'products.add', 'products.edit', 'products.delete',
                'products.bulk_operations',
                'categories.view', 'categories.add_edit', 'categories.delete',
                'multimedia.view', 'multimedia.add_edit_own', 'multimedia.add_edit_any', 'multimedia.delete',
                'imports.view_own', 'imports.view_all', 'imports.run',
                'exports.view_own', 'exports.view_all', 'exports.run',
                'workflow.view', 'workflow.approve_reject',
                'agent.bulk_actions',
                // AICG-P1-03 (#2329) — recipes/voice are content-team
                // config: read + create (incl. clone); admin stays with
                // Owner/Admin.
                'settings.ai_content.read', 'settings.ai_content.create',
                'audit.view_own',
                'api_tokens.own.crud',
                // ULV-04a (#985) — universal ObjectListView verbs.
                'object.view', 'object.add', 'object.edit', 'object.delete', 'object.export',
            ],

            // Marketing — view/add/edit products (NO delete), categories CRU, own multimedia, view-only audit
            'marketing' => [
                'products.view', 'products.add', 'products.edit', 'products.bulk_operations',
                'categories.view', 'categories.add_edit',
                'multimedia.view', 'multimedia.add_edit_own',
                'exports.view_own', 'exports.run',
                'imports.view_own', 'imports.run',
                'workflow.view',
                'agent.bulk_actions',
                // AICG-P1-03 (#2329) — same content-team grant as
                // catalog_manager.
                'settings.ai_content.read', 'settings.ai_content.create',
                'audit.view_own',
                'api_tokens.own.crud',
            ],

            // Modeler — full modeling, view rest
            'modeler' => [
                'modeling.view', 'modeling.attributes.add_edit', 'modeling.attribute_groups.add_edit',
                'modeling.object_types.add', 'modeling.delete_custom', 'modeling.approve_schema_ops',
                'modeling.auto_grant_new_object_types',
                'products.view', 'categories.view', 'multimedia.view',
                'agent.schema_ops', 'agent.approve_pending',
                'audit.view_own',
                'api_tokens.own.crud',
            ],

            // Integration Manager — integrations + tokens + imports + publish
            'integration_manager' => [
                'settings.integrations.manage', 'settings.integration_secrets.read',
                'imports.view_own', 'imports.view_all', 'imports.run',
                'publications.view', 'publications.publish_unpublish',
                'products.view',
                'api_tokens.own.crud', 'api_tokens.all.view_revoke',
                'audit.view_own',
            ],

            // Channel Manager — publish + view/edit catalog scoped to channel
            'channel_manager' => [
                'publications.view', 'publications.publish_unpublish',
                'products.view', 'products.edit',
                'categories.view', 'multimedia.view',
                'exports.view_own', 'exports.run',
                'audit.view_own',
                'api_tokens.own.crud',
            ],

            // Approver — view + approve workflow / pending changes
            'approver' => [
                'products.view', 'products.approve_pending_changes',
                'modeling.view', 'modeling.approve_schema_ops',
                'agent.approve_pending',
                'workflow.view', 'workflow.approve_reject',
                'audit.view_own', 'audit.view_cross_user',
                'api_tokens.own.crud',
            ],

            // Viewer — read-only everywhere
            'viewer' => [
                'products.view', 'categories.view', 'multimedia.view',
                'modeling.view', 'publications.view',
                'imports.view_own', 'exports.view_own',
                'workflow.view',
                'audit.view_own', 'audit.view_cross_user',
                'api_tokens.own.crud',
            ],
        ];
    }
}
