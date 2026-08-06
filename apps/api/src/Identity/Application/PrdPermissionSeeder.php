<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * PRD-PIM-rbac §3.2 permission catalogue as a prod-safe, idempotent seeder.
 *
 * The catalogue used to live only in {@see \App\DataFixtures\Identity\PrdPermissionFixtures},
 * a DoctrineFixtures class that does not exist in the production image
 * (fixtures are a dev dependency). A production database therefore came up
 * with just the 76 legacy RbacMatrix codes from the migrations while the 51
 * PRD codes were missing, so `SeedTenantPrdRolesService` could attach almost
 * nothing to `tenant_owner` (6 grants instead of 114) and the per-attribute
 * read overlay hid every attribute value in the API. Found on the first real
 * deployment of v1.0.0-alpha.1.
 *
 * The fixtures class now delegates here so dev, test and prod seed from one
 * list. Idempotent: existing codes are skipped, so it is safe to re-run on a
 * database that was bootstrapped before this seeder existed.
 */
final readonly class PrdPermissionSeeder
{
    /**
     * The atomic permissions from PRD-PIM-rbac §3.2 macierz.
     * Order: Cross-tenant → Products → Categories → Multimedia →
     * Modeling → Publications → Imports → Exports → Workflow →
     * Cmd+K agent → Settings → API tokens → Audit → Tenant lifecycle.
     *
     * @var list<string>
     */
    public const array PRD_PERMISSION_CODES = [
        // Cross-tenant (Super Admin only)
        'platform.tenants.list',
        'platform.tenants.manage',
        'platform.audit.view_all',
        'platform.break_glass_recovery',

        // Produkty
        'products.view',
        'products.add',
        'products.edit',
        'products.delete',
        'products.bulk_operations',
        'products.approve_pending_changes',

        // Kategorie
        'categories.view',
        'categories.add_edit',
        'categories.delete',

        // Multimedia (DAM)
        'multimedia.view',
        'multimedia.add_edit_own',
        'multimedia.add_edit_any',
        'multimedia.delete',

        // Modelowanie
        'modeling.view',
        'modeling.attributes.add_edit',
        'modeling.attribute_groups.add_edit',
        'modeling.object_types.add',
        'modeling.delete_custom',
        'modeling.approve_schema_ops',
        'modeling.auto_grant_new_object_types',

        // Publikacje
        'publications.view',
        'publications.publish_unpublish',

        // Imports
        'imports.view_own',
        'imports.view_all',
        'imports.run',

        // Exports
        'exports.view_own',
        'exports.view_all',
        'exports.run',

        // Workflow
        'workflow.view',
        'workflow.approve_reject',
        'workflow.edit_any_state',
        // WFL-P1-01 (#2415) — PRD §3.8 state-policy codes, seeded dormant
        // by RBAC Phase 3 docs and activated by the object_editorial guards.
        'workflow.edit_in_review',
        'workflow.transition.unpublish',
        'workflow.manage_definitions',

        // Cmd+K agent
        'agent.schema_ops',
        'agent.bulk_actions',
        'agent.approve_pending',

        // Settings
        'settings.users.manage',
        'settings.roles.manage',
        'settings.tenant.manage',
        'settings.locales.manage',
        'settings.billing.manage',
        'settings.integrations.manage',
        'settings.integration_secrets.read',
        // AICG-P1-03 (#2329, ADR-0030) — AI content settings
        // (ContentRecipe + BrandVoiceProfile): read = list/view,
        // create = add + clone built-in, admin = edit/delete/set-default.
        'settings.ai_content.read',
        'settings.ai_content.create',
        'settings.ai_content.admin',

        // API tokens
        'api_tokens.own.crud',
        'api_tokens.all.view_revoke',

        // Audit
        'audit.view_own',
        'audit.view_cross_user',

        // Tenant lifecycle
        'tenant.delete',

        // ULV-04a (#985) — generic ObjectType-scoped verbs for the
        // universal ObjectListView. Cover every ObjectType (built-in +
        // custom). The legacy per-kind codes (products.*, categories.*,
        // multimedia.* etc.) keep working in parallel; ULV-04a only adds
        // the generic verbs the universal list endpoint and per-ObjectType
        // voter consume.
        //
        // Per-ObjectType grant scoping (e.g. `object.view` granted only
        // for ObjectType=Cars but not Bikes) is enforced by the new
        // `ObjectScopedVoter` via the user_role_assignments scope payload
        // (locale/channel/attribute_group_scope already exists; a future
        // RBAC ticket adds object_type_scope without touching the
        // permission catalogue here).
        'object.view',
        'object.add',
        'object.edit',
        'object.delete',
        'object.export',
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return int number of permissions created (0 when already complete)
     */
    public function seed(): int
    {
        $repo = $this->em->getRepository(Permission::class);
        $created = 0;

        foreach (self::PRD_PERMISSION_CODES as $code) {
            if (null !== $repo->findOneBy(['code' => $code])) {
                continue; // idempotent — already seeded
            }

            [$resource, $action] = self::splitCode($code);
            $this->em->persist(new Permission(
                resource: $resource,
                action: $action,
                code: $code,
                id: Uuid::v7(),
            ));
            ++$created;
        }

        if ($created > 0) {
            $this->em->flush();
        }

        return $created;
    }

    /**
     * Split a PRD permission code into (resource, action) on the LAST dot.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitCode(string $code): array
    {
        $lastDot = strrpos($code, '.');
        if (false === $lastDot) {
            return ['', $code];
        }

        return [substr($code, 0, $lastDot), substr($code, $lastDot + 1)];
    }
}
