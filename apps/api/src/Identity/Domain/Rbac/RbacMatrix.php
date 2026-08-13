<?php

declare(strict_types=1);

namespace App\Identity\Domain\Rbac;

/**
 * Source of truth for the four built-in roles and their permissions.
 *
 * The matrix is a static, declarative listing — same shape that lands in
 * docs/rbac.md so engineers and the seeder read identical rules. Adding a
 * permission is a two-step change: extend RESOURCES/ACTIONS, then grant it
 * to the relevant role(s) below.
 *
 * Resources cover both entities that exist today (User, Role, Tenant) and
 * ones arriving with epics 0.3 (Object, ObjectType, Attribute, AttributeGroup,
 * Asset, Brand, Category) and 0.6 (Channel, Integration, ApiProfile). The
 * seeder is intentionally allowed to reference future resources — voters and
 * API surfaces will catch up to the matrix as they land.
 */
final class RbacMatrix
{
    public const string ROLE_SUPER_ADMIN = 'super_admin';

    /**
     * #2837 — these three are TENANT roles, provisioned per tenant by
     * {@see \App\Identity\Application\SeedTenantPrdRolesService} from the
     * PRD §3.2 templates. The constants stay as the canonical spelling of
     * their codes, but {@see roles()} no longer defines them: seeding them
     * globally as well produced two rows per code — identical in the panel,
     * different in what they granted (`viewer` held 11 permissions on one
     * side and 17 on the other), so the access a user got depended on which
     * row the panel happened to pick.
     */
    public const string ROLE_CATALOG_MANAGER = 'catalog_manager';
    public const string ROLE_INTEGRATION_MANAGER = 'integration_manager';
    public const string ROLE_VIEWER = 'viewer';

    /**
     * AUD-003 (#1575) — platform-level operator role. Global (tenant_id
     * NULL) but, unlike `super_admin`, it is NEVER assigned to a tenant
     * Owner. It is the only role holding the cross-tenant `platform.*`
     * permissions, so the `/api/admin/tenants/*` operator panel and the
     * break-glass endpoints gate on a grant a regular Owner cannot reach.
     */
    public const string ROLE_PLATFORM_OPERATOR = 'platform_operator';

    /**
     * Cross-tenant platform permission codes (PRD-PIM-rbac §3.2 "Super
     * Admin only" block). These are intentionally NOT part of the legacy
     * resource×action grid — they are held exclusively by
     * `platform_operator`, never by `super_admin`, so a tenant Owner who
     * carries `super_admin` cannot manage / recon other tenants.
     */
    public const string PERMISSION_PLATFORM_TENANTS_LIST = 'platform.tenants.list';
    public const string PERMISSION_PLATFORM_TENANTS_MANAGE = 'platform.tenants.manage';
    public const string PERMISSION_PLATFORM_AUDIT_VIEW_ALL = 'platform.audit.view_all';
    public const string PERMISSION_PLATFORM_BREAK_GLASS = 'platform.break_glass_recovery';

    public const string ACTION_READ = 'read';
    public const string ACTION_WRITE = 'write';
    public const string ACTION_DELETE = 'delete';
    public const string ACTION_ADMIN = 'admin';

    /**
     * Resources known to the matrix. Order is alphabetic.
     */
    /**
     * Resources known to the matrix. Order is alphabetic.
     *
     * ADR-014 / MOD-02 (#894): `association` removed — Association infra was
     * dormant (no controllers, no consumers) and ADR-014 replaces it with
     * `relation`-typed Attributes covered by `attribute.*` permissions.
     */
    private const array RESOURCES = [
        'api_profile',
        'asset',
        'attribute',
        'attribute_group',
        'backup',
        'category',
        'channel',
        'import_profile',
        'import_schedule',
        'import_session',
        'import_source',
        'integration',
        'object',
        'object_type',
        'role',
        'tenant',
        'user',
    ];

    private const array ACTIONS = [
        self::ACTION_READ,
        self::ACTION_WRITE,
        self::ACTION_DELETE,
        self::ACTION_ADMIN,
    ];

    /**
     * Cross-tenant permission codes split as (resource, action) on the
     * last segment so they slot into the same `permissions` table as the
     * legacy grid. `platform.tenants.manage` → (platform.tenants, manage).
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array PLATFORM_PERMISSIONS = [
        ['platform.tenants', 'list'],
        ['platform.tenants', 'manage'],
        ['platform.audit', 'view_all'],
        ['platform', 'break_glass_recovery'],
    ];

    /**
     * @return list<PermissionDefinition>
     */
    public static function permissions(): array
    {
        $permissions = [];
        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                $permissions[] = new PermissionDefinition($resource, $action);
            }
        }

        // AUD-003 (#1575): platform-scope permissions live in the same
        // pool so `pim:rbac:seed` provisions them in production (not only
        // dev fixtures), but they are granted exclusively to
        // `platform_operator` — see roles() below.
        foreach (self::PLATFORM_PERMISSIONS as [$resource, $action]) {
            $permissions[] = new PermissionDefinition($resource, $action);
        }

        return $permissions;
    }

    /**
     * The GLOBAL (tenant_id NULL) roles — platform scope only.
     *
     * #2837 — tenant-facing roles (catalog_manager, integration_manager,
     * viewer, …) are NOT here: they belong to the per-tenant PRD templates
     * and seeding them from both places created duplicate codes with
     * diverging permission sets. What stays global is what genuinely has
     * no tenant: `super_admin` (held by a tenant Owner, but the row itself
     * is shared) and `platform_operator` (cross-tenant `platform.*`).
     *
     * @return list<RoleDefinition>
     */
    public static function roles(): array
    {
        return [
            new RoleDefinition(
                code: self::ROLE_SUPER_ADMIN,
                name: 'Super Admin',
                permissionCodes: self::allPermissionCodes(),
            ),
            // AUD-003 (#1575) — platform operator: the ONLY role with the
            // cross-tenant `platform.*` grants. Never assigned to a tenant
            // Owner, so the operator panel + break-glass stay platform-only.
            new RoleDefinition(
                code: self::ROLE_PLATFORM_OPERATOR,
                name: 'Platform Operator',
                permissionCodes: self::platformPermissionCodes(),
            ),
        ];
    }

    /**
     * Every legacy resource×action code. Excludes the `platform.*` block
     * by construction (it is not part of the grid) so `super_admin` —
     * which a tenant Owner holds — never gains cross-tenant authority.
     *
     * @return list<string>
     */
    private static function allPermissionCodes(): array
    {
        $codes = [];
        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                $codes[] = \sprintf('%s.%s', $resource, $action);
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private static function platformPermissionCodes(): array
    {
        return [
            self::PERMISSION_PLATFORM_TENANTS_LIST,
            self::PERMISSION_PLATFORM_TENANTS_MANAGE,
            self::PERMISSION_PLATFORM_AUDIT_VIEW_ALL,
            self::PERMISSION_PLATFORM_BREAK_GLASS,
        ];
    }
}
