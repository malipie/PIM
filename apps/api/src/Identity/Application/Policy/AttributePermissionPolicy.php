<?php

declare(strict_types=1);

namespace App\Identity\Application\Policy;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\AttributePermission;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * RBAC-P3-008 (#671) — 3-state attribute permission resolution per
 * PRD §3.5.
 *
 * **Resolution chain (per role, then merged most-permissive):**
 *
 *   0. **Broad gate first** (decyzja designerska A) — the caller must
 *      hold either `products.view` or `products.edit` in the macierz.
 *      If neither, every per-attribute grant is inactive and the policy
 *      returns `Restricted` immediately.
 *
 *   1. **Per-attribute override** —
 *      `role_attribute_permissions(role_id, attribute_id)`. First entry
 *      wins for that role.
 *
 *   2. **Per-group override** —
 *      `role_attribute_group_permissions(role_id, attribute_group_id)`.
 *      The attribute can belong to multiple groups via
 *      `attribute_group_attributes`; the most-permissive group entry
 *      among them is taken.
 *
 *   3. **Role default** — `roles.default_attribute_permission` falls
 *      back to one of the three values (defaults to `edit` per schema).
 *
 * **Multi-role merging:** the user can carry multiple roles (both via
 * `user_role_assignments` and the legacy `user_roles` M2M). The policy
 * resolves per role and returns `max(rank)` — most-permissive role
 * wins. This matches PermissionResolver's union semantics for broad
 * permissions.
 *
 * **Tenant scope:** roles are tenant-scoped through `roles.tenant_id`;
 * `user_roles` / `user_role_assignments` carry the link from a
 * tenant-scoped user to a tenant-scoped role. No additional tenant
 * compare is needed here — the role IDs we collect are already inside
 * the caller's tenant.
 *
 * **Cache (#2794):** the follow-up promised by #671 — profiling on the
 * 50k production catalog showed the call path IS hot enough. The old
 * shape issued four SELECTs per attribute (role ids, per-attribute
 * grant, per-group grant, role default), so a 32-attribute ObjectType
 * cost **128 queries per request** — a constant that did not shrink with
 * `itemsPerPage`, which is why it dominated `GET /api/products` latency
 * (GOLIVE #2234). Two changes remove it:
 *
 *   - {@see resolvePermissions()} resolves a whole attribute batch with
 *     three set-based SELECTs (`… WHERE role_id IN (…) AND attribute_id
 *     IN (…)`) instead of four per attribute;
 *   - the per-request caches below make repeat lookups free.
 *
 * **Worker-mode hygiene:** FrankenPHP keeps service instances alive
 * between requests, so a grant cache that outlived the request would
 * hand user B the decisions computed for user A. Every grant cache is
 * therefore cleared in {@see reset()} (autoconfigured `kernel.reset`,
 * same contract as TenantContext / BulkContext) AND keyed by the full
 * user id — belt and braces, because the failure mode is a permission
 * leak, not a stale number.
 *
 * **`integration_visible` flag:** independent semantics, lives in the
 * serializer (RBAC-P3-012 #675). This policy resolves only the per-role
 * 3-state values — the serializer composes them with the integration
 * flag for the final response shape.
 */
class AttributePermissionPolicy implements ResetInterface
{
    /**
     * Memoised schema-existence probes (#1620). The `information_schema`
     * introspection in {@see tableExists()} / {@see columnExists()} is the
     * same for every attribute and role in a request — and the schema is
     * stable for a worker's whole lifetime — yet it ran once per role per
     * attribute, which on a 200-item collection page (resolved one attribute
     * id at a time by the read overlay) dominated the GET latency. Caching
     * the boolean result removes that cost without changing any permission
     * decision; the SELECTs that read actual grant rows are NOT cached.
     *
     * @var array<string, bool>
     */
    private array $schemaProbeCache = [];

    /**
     * Broad-gate outcome per user id (#2794). Step 0 asked
     * {@see PermissionResolverInterface} once per attribute; on a
     * 32-attribute page that is 31 redundant resolves.
     *
     * @var array<string, bool>
     */
    private array $broadGateCache = [];

    /**
     * Role ids per user id (#2794) — replaces one `collectRoleIds()`
     * round-trip per attribute.
     *
     * @var array<string, list<string>>
     */
    private array $roleIdsCache = [];

    /**
     * Resolved default per role id (#2794).
     *
     * @var array<string, AttributePermission>
     */
    private array $roleDefaultCache = [];

    /**
     * Final decisions keyed `userId:attributeId` (#2794).
     *
     * @var array<string, AttributePermission>
     */
    private array $decisionCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly PermissionResolverInterface $resolver,
    ) {
    }

    public function resolvePermission(User $user, Uuid $attributeId): AttributePermission
    {
        $resolved = $this->resolvePermissions($user, [$attributeId]);

        return $resolved[$attributeId->toRfc4122()] ?? AttributePermission::Restricted;
    }

    /**
     * Batch counterpart of {@see resolvePermission()} (#2794).
     *
     * Resolves the whole attribute set with three set-based SELECTs
     * instead of four queries per attribute. The resolution chain,
     * its priority order and the most-permissive multi-role merge are
     * byte-for-byte the same decisions the per-attribute path made —
     * only the number of round-trips changes.
     *
     * @param list<Uuid> $attributeIds
     *
     * @return array<string, AttributePermission> keyed by RFC4122 attribute id
     */
    public function resolvePermissions(User $user, array $attributeIds): array
    {
        if ([] === $attributeIds) {
            return [];
        }

        $userKey = $user->getId()->toRfc4122();

        // Step 0 — broad gate first (PRD §3.5 decyzja designerska A).
        if (!$this->passesBroadGate($user, $userKey)) {
            return $this->allRestricted($attributeIds);
        }

        $roleIds = $this->roleIdsFor($user, $userKey);
        if ([] === $roleIds) {
            return $this->allRestricted($attributeIds);
        }

        $resolved = [];
        $pending = [];
        foreach ($attributeIds as $attributeId) {
            $attributeKey = $attributeId->toRfc4122();
            $cached = $this->decisionCache[$userKey.':'.$attributeKey] ?? null;
            if ($cached instanceof AttributePermission) {
                $resolved[$attributeKey] = $cached;
                continue;
            }
            // Deduplicate: the same attribute id may repeat in the batch.
            $pending[$attributeKey] = $attributeKey;
        }

        if ([] === $pending) {
            return $resolved;
        }

        $pendingKeys = array_values($pending);
        $perAttribute = $this->fetchPerAttributeGrants($roleIds, $pendingKeys);
        $perGroup = $this->fetchPerGroupGrants($roleIds, $pendingKeys);
        $defaults = $this->roleDefaults($roleIds);

        foreach ($pendingKeys as $attributeKey) {
            $best = AttributePermission::Restricted;
            foreach ($roleIds as $roleId) {
                // Same priority as the per-role chain: per-attribute
                // override > per-group override > role default.
                $forRole = $perAttribute[$roleId][$attributeKey]
                    ?? $perGroup[$roleId][$attributeKey]
                    ?? $defaults[$roleId]
                    ?? AttributePermission::Restricted;

                if ($forRole->rank() > $best->rank()) {
                    $best = $forRole;
                }
                if (AttributePermission::Edit === $best) {
                    // Most-permissive already reached — no role can beat it.
                    break;
                }
            }

            $this->decisionCache[$userKey.':'.$attributeKey] = $best;
            $resolved[$attributeKey] = $best;
        }

        return $resolved;
    }

    /**
     * Drops every grant cache between requests. FrankenPHP worker mode
     * reuses this service instance, so skipping this would leak one
     * user's decisions into the next request (#2794).
     *
     * `schemaProbeCache` deliberately survives: DDL does not change
     * inside a worker's lifetime and the probe result carries no
     * user-scoped information.
     */
    public function reset(): void
    {
        $this->broadGateCache = [];
        $this->roleIdsCache = [];
        $this->roleDefaultCache = [];
        $this->decisionCache = [];
    }

    public function canEditAttribute(User $user, Uuid $attributeId): bool
    {
        return $this->resolvePermission($user, $attributeId)->canEdit();
    }

    public function canViewAttribute(User $user, Uuid $attributeId): bool
    {
        return $this->resolvePermission($user, $attributeId)->canView();
    }

    /**
     * @return list<string> role UUIDs (RFC4122 strings) accessible to the
     *                      user via either `user_role_assignments` or the
     *                      legacy `user_roles` M2M, deduplicated
     */
    private function collectRoleIds(Uuid $userId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT role_id::text AS role_id
              FROM user_role_assignments
             WHERE user_id = :user_id
            UNION
            SELECT DISTINCT role_id::text AS role_id
              FROM user_roles
             WHERE user_id = :user_id
            SQL;

        /** @var list<array{role_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['user_id' => $userId->toRfc4122()]);

        return array_map(static fn (array $row): string => $row['role_id'], $rows);
    }

    /**
     * Step 1 of the chain for the whole batch — per-attribute overrides.
     *
     * The column name (`permission_level`) follows the entity ORM mapping
     * in {@see RoleAttributePermission.orm.xml}; an older iteration of
     * {@see Version20260518160000} declared `permission` and never landed.
     *
     * @param list<string> $roleIds
     * @param list<string> $attributeIds
     *
     * @return array<string, array<string, AttributePermission>> role id → attribute id → grant
     */
    private function fetchPerAttributeGrants(array $roleIds, array $attributeIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT role_id::text AS role_id, attribute_id::text AS attribute_id, permission_level
                  FROM role_attribute_permissions
                 WHERE role_id IN (:role_ids)
                   AND attribute_id IN (:attribute_ids)
                SQL,
            ['role_ids' => $roleIds, 'attribute_ids' => $attributeIds],
            ['role_ids' => ArrayParameterType::STRING, 'attribute_ids' => ArrayParameterType::STRING],
        );

        return $this->indexGrants($rows);
    }

    /**
     * Step 2 for the whole batch — per-group overrides. An attribute can
     * belong to several groups, so the most-permissive grant wins; the
     * single-attribute path expressed that as `ORDER BY … LIMIT 1`, the
     * batch keeps `max(rank)` in {@see indexGrants()} instead.
     *
     * `role_attribute_group_permissions` is only present on DBs that ran
     * {@see Version20260518160000}. Local stacks bootstrapped via
     * `doctrine:schema:create` from entities never get the table (no
     * entity exists for it). Treat a missing table as "no per-group
     * override" — same outcome as an empty row set.
     *
     * AUD-008 (#1578): probe the schema with the SchemaManager (reads
     * information_schema) BEFORE issuing the SELECT. A failing SELECT
     * inside an open transaction aborts the whole transaction in
     * PostgreSQL (SQLSTATE 25P02) even when the PHP exception is caught —
     * and this resolver also runs inside the write path's transaction
     * (ObjectAttributesUpserter). Probing first never dirties it.
     *
     * @param list<string> $roleIds
     * @param list<string> $attributeIds
     *
     * @return array<string, array<string, AttributePermission>> role id → attribute id → grant
     */
    private function fetchPerGroupGrants(array $roleIds, array $attributeIds): array
    {
        if (!$this->tableExists('role_attribute_group_permissions')) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT rgp.role_id::text AS role_id, aga.attribute_id::text AS attribute_id, rgp.permission_level
                  FROM role_attribute_group_permissions rgp
                  JOIN attribute_group_attributes aga ON aga.attribute_group_id = rgp.attribute_group_id
                 WHERE rgp.role_id IN (:role_ids)
                   AND aga.attribute_id IN (:attribute_ids)
                SQL,
            ['role_ids' => $roleIds, 'attribute_ids' => $attributeIds],
            ['role_ids' => ArrayParameterType::STRING, 'attribute_ids' => ArrayParameterType::STRING],
        );

        return $this->indexGrants($rows);
    }

    /**
     * Folds grant rows into `role → attribute → permission`, keeping the
     * most-permissive value when a pair repeats (per-group overlap).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, AttributePermission>>
     */
    private function indexGrants(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $level = $row['permission_level'] ?? null;
            $roleId = $row['role_id'] ?? null;
            $attributeId = $row['attribute_id'] ?? null;
            if (!\is_string($level) || !\is_string($roleId) || !\is_string($attributeId)) {
                continue;
            }

            $grant = AttributePermission::from($level);

            $current = $indexed[$roleId][$attributeId] ?? null;
            if (!$current instanceof AttributePermission || $grant->rank() > $current->rank()) {
                $indexed[$roleId][$attributeId] = $grant;
            }
        }

        return $indexed;
    }

    /**
     * Step 3 for the whole batch — role defaults, memoised per role id.
     *
     * `roles.default_attribute_permission` defaults to `edit` for
     * tenant-level roles, `view` for viewer, `restricted` for explicit
     * setups. The column only exists when the original migration ran;
     * otherwise we infer from `roles.code` to preserve the migration's
     * intent (`viewer` → View, everyone else who passed the broad gate →
     * Edit). Both columns come back in one round-trip so the fallback
     * costs nothing extra.
     *
     * @param list<string> $roleIds
     *
     * @return array<string, AttributePermission>
     */
    private function roleDefaults(array $roleIds): array
    {
        $missing = array_values(array_diff($roleIds, array_keys($this->roleDefaultCache)));

        if ([] !== $missing) {
            $hasDefaultColumn = $this->columnExists('roles', 'default_attribute_permission');
            $sql = $hasDefaultColumn
                ? 'SELECT id::text AS role_id, code, default_attribute_permission FROM roles WHERE id IN (:role_ids)'
                : 'SELECT id::text AS role_id, code FROM roles WHERE id IN (:role_ids)';

            $rows = $this->connection->fetchAllAssociative(
                $sql,
                ['role_ids' => $missing],
                ['role_ids' => ArrayParameterType::STRING],
            );

            foreach ($rows as $row) {
                $roleId = $row['role_id'] ?? null;
                if (!\is_string($roleId)) {
                    continue;
                }
                $level = $hasDefaultColumn ? ($row['default_attribute_permission'] ?? null) : null;
                $code = $row['code'] ?? null;

                $this->roleDefaultCache[$roleId] = \is_string($level)
                    ? AttributePermission::from($level)
                    : $this->inferDefaultFromRoleCode(\is_string($code) ? $code : null);
            }

            // A role id that no longer resolves to a row mirrors the old
            // `fetchOne() === false` branch: nothing to grant.
            foreach ($missing as $roleId) {
                $this->roleDefaultCache[$roleId] ??= AttributePermission::Restricted;
            }
        }

        $defaults = [];
        foreach ($roleIds as $roleId) {
            $defaults[$roleId] = $this->roleDefaultCache[$roleId] ?? AttributePermission::Restricted;
        }

        return $defaults;
    }

    /**
     * Compatibility fallback when `roles.default_attribute_permission`
     * is missing (DB created from entities, not migrations). Mirrors the
     * UPDATEs from {@see Version20260518160000}: `viewer` → View,
     * everyone else who already passed the broad gate → Edit.
     */
    private function inferDefaultFromRoleCode(?string $code): AttributePermission
    {
        if (null === $code) {
            return AttributePermission::Restricted;
        }

        return 'viewer' === $code ? AttributePermission::View : AttributePermission::Edit;
    }

    private function passesBroadGate(User $user, string $userKey): bool
    {
        return $this->broadGateCache[$userKey] ??= (function () use ($user): bool {
            $permissions = $this->resolver->resolve($user);

            return $permissions->has('products.view') || $permissions->has('products.edit');
        })();
    }

    /**
     * @return list<string>
     */
    private function roleIdsFor(User $user, string $userKey): array
    {
        return $this->roleIdsCache[$userKey] ??= $this->collectRoleIds($user->getId());
    }

    /**
     * @param list<Uuid> $attributeIds
     *
     * @return array<string, AttributePermission>
     */
    private function allRestricted(array $attributeIds): array
    {
        $restricted = [];
        foreach ($attributeIds as $attributeId) {
            $restricted[$attributeId->toRfc4122()] = AttributePermission::Restricted;
        }

        return $restricted;
    }

    /**
     * Transaction-safe existence probe. Reads `information_schema` via the
     * SchemaManager instead of issuing a SELECT that would abort an open
     * transaction (SQLSTATE 25P02) when the table is absent on a DB built
     * from ORM metadata rather than migrations.
     *
     * @param non-empty-string $table
     */
    private function tableExists(string $table): bool
    {
        return $this->schemaProbeCache['table:'.$table] ??= $this->connection
            ->createSchemaManager()
            ->tablesExist([$table]);
    }

    /**
     * @param non-empty-string $table
     */
    private function columnExists(string $table, string $column): bool
    {
        return $this->schemaProbeCache['column:'.$table.'.'.$column] ??= $this->probeColumn($table, $column);
    }

    /**
     * @param non-empty-string $table
     */
    private function probeColumn(string $table, string $column): bool
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist([$table])) {
            return false;
        }

        return $schemaManager->introspectTableByUnquotedName($table)->hasColumn($column);
    }
}
