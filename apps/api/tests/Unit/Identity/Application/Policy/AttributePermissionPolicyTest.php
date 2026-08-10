<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application\Policy;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Application\Policy\AttributePermissionPolicy;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\AttributePermission;
use App\Identity\Domain\Rbac\PermissionSet;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-008 (#671) — unit coverage of AttributePermissionPolicy
 * resolution priority per PRD §3.5.
 *
 * Connection-level interactions are stubbed so each test exercises a
 * single branch of the resolution chain: broad gate / per-attribute /
 * per-group / role-default / multi-role merge.
 *
 * #2794 rewrote the internals from four SELECTs per attribute to three
 * set-based SELECTs per batch. The decisions asserted here did not
 * change — only the query shapes the stubs answer to.
 */
final class AttributePermissionPolicyTest extends TestCase
{
    private const string ROLE_ID = '01931700-0000-7000-8000-000000000001';
    private const string ROLE_ID_SECOND = '01931700-0000-7000-8000-000000000002';

    #[Test]
    public function returnsRestrictedWhenBroadGateMissing(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, []);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $connection->expects(self::never())->method('fetchOne');

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::Restricted, $policy->resolvePermission($user, $attrId));
    }

    #[Test]
    public function perAttributeOverrideWinsOverGroupAndDefault(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            perAttribute: AttributePermission::Edit->value,
            perGroup: AttributePermission::View->value,
            roleDefault: AttributePermission::Restricted->value,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::Edit, $policy->resolvePermission($user, $attrId));
    }

    #[Test]
    public function perGroupOverrideWinsWhenAttributeOverrideAbsent(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            perGroup: AttributePermission::View->value,
            roleDefault: AttributePermission::Restricted->value,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::View, $policy->resolvePermission($user, $attrId));
    }

    #[Test]
    public function mostPermissiveGroupWinsWhenAttributeSitsInSeveralGroups(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        // Two group rows for the same (role, attribute) pair — the
        // single-attribute path expressed this as ORDER BY … LIMIT 1.
        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            perGroup: AttributePermission::View->value,
            extraGroupLevel: AttributePermission::Edit->value,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::Edit, $policy->resolvePermission($user, $attrId));
    }

    #[Test]
    public function fallsBackToRoleDefault(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            roleDefault: AttributePermission::View->value,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::View, $policy->resolvePermission($user, $attrId));
    }

    #[Test]
    public function fallsBackToRoleCodeWhenDefaultColumnHoldsNoValue(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(roleIds: [self::ROLE_ID], roleCode: 'viewer');

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::View, $policy->resolvePermission($user, $attrId));
    }

    #[Test]
    public function takesMostPermissiveAcrossMultipleRoles(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.view']);

        // First role: per-attribute Restricted. Second role: per-group Edit.
        // Most permissive wins → Edit.
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params = []) use ($attrId): array {
                if (str_contains($sql, 'FROM user_role_assignments')) {
                    return [
                        ['role_id' => self::ROLE_ID],
                        ['role_id' => self::ROLE_ID_SECOND],
                    ];
                }
                if (str_contains($sql, 'FROM role_attribute_permissions')) {
                    return [[
                        'role_id' => self::ROLE_ID,
                        'attribute_id' => $attrId->toRfc4122(),
                        'permission_level' => AttributePermission::Restricted->value,
                    ]];
                }
                if (str_contains($sql, 'FROM role_attribute_group_permissions')) {
                    return [[
                        'role_id' => self::ROLE_ID_SECOND,
                        'attribute_id' => $attrId->toRfc4122(),
                        'permission_level' => AttributePermission::Edit->value,
                    ]];
                }
                if (str_contains($sql, 'FROM roles WHERE id IN')) {
                    return [
                        ['role_id' => self::ROLE_ID, 'code' => 'viewer', 'default_attribute_permission' => AttributePermission::Restricted->value],
                        ['role_id' => self::ROLE_ID_SECOND, 'code' => 'viewer', 'default_attribute_permission' => AttributePermission::Restricted->value],
                    ];
                }

                return [];
            });
        $this->withSchema($connection);

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::Edit, $policy->resolvePermission($user, $attrId));
    }

    #[Test]
    public function returnsRestrictedWhenUserCarriesNoRoles(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::Restricted, $policy->resolvePermission($user, $attrId));
    }

    #[Test]
    public function canEditAttributeReflectsEditPermission(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.edit']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            perAttribute: AttributePermission::Edit->value,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertTrue($policy->canEditAttribute($user, $attrId));
        self::assertTrue($policy->canViewAttribute($user, $attrId));
    }

    #[Test]
    public function canEditFalseWhenOnlyViewGranted(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            perAttribute: AttributePermission::View->value,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertFalse($policy->canEditAttribute($user, $attrId));
        self::assertTrue($policy->canViewAttribute($user, $attrId));
    }

    #[Test]
    public function canViewFalseWhenRestricted(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            perAttribute: AttributePermission::Restricted->value,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertFalse($policy->canViewAttribute($user, $attrId));
        self::assertFalse($policy->canEditAttribute($user, $attrId));
    }

    /**
     * #2794 — the whole point of the batch API: query count must be a
     * constant, not a multiple of the attribute count. Four attributes
     * used to cost 16 SELECTs; the batch costs the same four as one.
     */
    #[Test]
    public function batchResolveIssuesConstantNumberOfQueries(): void
    {
        $user = $this->user();
        $attributeIds = [Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v7()];

        $queryLog = [];
        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            roleDefault: AttributePermission::Edit->value,
            queryLog: $queryLog,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);
        $resolved = $policy->resolvePermissions($user, $attributeIds);

        self::assertCount(4, $resolved);
        foreach ($attributeIds as $attributeId) {
            self::assertSame(AttributePermission::Edit, $resolved[$attributeId->toRfc4122()]);
        }

        // role ids + per-attribute grants + per-group grants + role defaults
        self::assertCount(4, $queryLog, 'batch resolve must not scale queries with attribute count');
    }

    /**
     * #2794 — repeat lookups inside one request must not re-query, and
     * {@see AttributePermissionPolicy::resolvePermission()} must reuse
     * the batch cache.
     */
    #[Test]
    public function repeatLookupsWithinRequestAreServedFromCache(): void
    {
        $user = $this->user();
        $attrId = Uuid::v7();

        $queryLog = [];
        $resolver = $this->resolverWith($user, ['products.view']);
        $connection = $this->stubConnection(
            roleIds: [self::ROLE_ID],
            roleDefault: AttributePermission::Edit->value,
            queryLog: $queryLog,
        );

        $policy = new AttributePermissionPolicy($connection, $resolver);

        $policy->resolvePermission($user, $attrId);
        $countAfterFirst = \count($queryLog);
        $policy->resolvePermission($user, $attrId);
        $policy->canEditAttribute($user, $attrId);

        self::assertCount($countAfterFirst, $queryLog, 'cached decision must not issue further queries');
    }

    /**
     * #2794 — the security-critical half of the cache. FrankenPHP worker
     * mode reuses the service instance across requests; without
     * {@see AttributePermissionPolicy::reset()} the second user would
     * inherit the first user's decisions.
     */
    #[Test]
    public function resetClearsDecisionsBetweenRequests(): void
    {
        $attrId = Uuid::v7();
        $alice = $this->user('alice@alpha.localhost');
        $bob = $this->user('bob@alpha.localhost');

        $resolver = $this->createMock(PermissionResolverInterface::class);
        $resolver->method('resolve')->willReturn(new PermissionSet(['products.view']));

        $connection = $this->createMock(Connection::class);
        $grant = AttributePermission::Edit->value;
        $connection->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($attrId, &$grant): array {
                if (str_contains($sql, 'FROM user_role_assignments')) {
                    return [['role_id' => self::ROLE_ID]];
                }
                if (str_contains($sql, 'FROM role_attribute_permissions')) {
                    return [[
                        'role_id' => self::ROLE_ID,
                        'attribute_id' => $attrId->toRfc4122(),
                        'permission_level' => $grant,
                    ]];
                }

                return [];
            });
        $this->withSchema($connection);

        $policy = new AttributePermissionPolicy($connection, $resolver);

        self::assertSame(AttributePermission::Edit, $policy->resolvePermission($alice, $attrId));

        // Next request: same worker, same service instance, different
        // user whose role carries a narrower grant.
        $grant = AttributePermission::Restricted->value;
        $policy->reset();

        self::assertSame(
            AttributePermission::Restricted,
            $policy->resolvePermission($bob, $attrId),
            'reset() must drop grant caches — otherwise worker mode leaks decisions across users',
        );
    }

    private function user(string $email = 'tester@alpha.localhost'): User
    {
        return new User(
            new Tenant('alpha', 'Alpha'),
            $email,
            'placeholder',
            ['ROLE_USER'],
            Uuid::v7(),
        );
    }

    /**
     * @param list<string> $codes
     */
    private function resolverWith(User $user, array $codes): PermissionResolverInterface
    {
        $resolver = $this->createMock(PermissionResolverInterface::class);
        $resolver->method('resolve')->with($user)->willReturn(new PermissionSet($codes));

        return $resolver;
    }

    /**
     * Answers the three set-based SELECTs of the #2794 batch path plus
     * the role-id lookup. Passing a level makes it apply to every role ×
     * attribute pair the policy asks for.
     *
     * @param list<string> $roleIds
     * @param list<string> $queryLog receives one entry per executed query
     */
    private function stubConnection(
        array $roleIds,
        ?string $perAttribute = null,
        ?string $perGroup = null,
        ?string $roleDefault = null,
        ?string $extraGroupLevel = null,
        string $roleCode = 'catalog_manager',
        array &$queryLog = [],
    ): Connection {
        $queryLog = [];
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchAllAssociative')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (
                    $roleIds,
                    $perAttribute,
                    $perGroup,
                    $roleDefault,
                    $extraGroupLevel,
                    $roleCode,
                    &$queryLog,
                ): array {
                    $queryLog[] = $sql;

                    if (str_contains($sql, 'FROM user_role_assignments')) {
                        return array_map(static fn (string $id): array => ['role_id' => $id], $roleIds);
                    }

                    /** @var list<string> $attributeIds */
                    $attributeIds = $params['attribute_ids'] ?? [];

                    if (str_contains($sql, 'FROM role_attribute_permissions')) {
                        return self::grantRows($roleIds, $attributeIds, $perAttribute);
                    }

                    if (str_contains($sql, 'FROM role_attribute_group_permissions')) {
                        return array_merge(
                            self::grantRows($roleIds, $attributeIds, $perGroup),
                            self::grantRows($roleIds, $attributeIds, $extraGroupLevel),
                        );
                    }

                    if (str_contains($sql, 'FROM roles WHERE id IN')) {
                        return array_map(static fn (string $id): array => [
                            'role_id' => $id,
                            'code' => $roleCode,
                            'default_attribute_permission' => $roleDefault,
                        ], $roleIds);
                    }

                    return [];
                },
            );
        $this->withSchema($connection);

        return $connection;
    }

    /**
     * @param list<string> $roleIds
     * @param list<string> $attributeIds
     *
     * @return list<array{role_id: string, attribute_id: string, permission_level: string}>
     */
    private static function grantRows(array $roleIds, array $attributeIds, ?string $level): array
    {
        if (null === $level) {
            return [];
        }

        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($attributeIds as $attributeId) {
                $rows[] = [
                    'role_id' => $roleId,
                    'attribute_id' => $attributeId,
                    'permission_level' => $level,
                ];
            }
        }

        return $rows;
    }

    /**
     * Stub the SchemaManager existence probes added in AUD-008 (#1578) so
     * the policy reaches its per-group / role-default SELECTs. Mirrors a
     * migrated DB where both the group table and the default column exist.
     *
     * @param Connection&MockObject $connection
     */
    private function withSchema(Connection $connection): void
    {
        $table = $this->createStub(Table::class);
        $table->method('hasColumn')->willReturn(true);

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('introspectTableByUnquotedName')->willReturn($table);

        $connection->method('createSchemaManager')->willReturn($schemaManager);
    }
}
