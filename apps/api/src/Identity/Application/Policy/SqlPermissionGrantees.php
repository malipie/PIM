<?php

declare(strict_types=1);

namespace App\Identity\Application\Policy;

use App\Identity\Contracts\Policy\PermissionGranteesInterface;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P2-02 (#2421) — reverse permission lookup over both coexisting
 * role-assignment paths (legacy `user_roles` M2M and RBAC-P1-008
 * `user_role_assignments`; consolidation tracked in #644). Raw DBAL
 * with an explicit tenant filter — same read-model pattern as the
 * Dashboard aggregates (ADR-0026); RLS backstops raw access.
 *
 * Only ACTIVE users qualify: notifying a suspended account would leak
 * workflow activity to principals who cannot even log in.
 */
final readonly class SqlPermissionGrantees implements PermissionGranteesInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function userIdsWithPermission(Tenant $tenant, string $permissionCode): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT u.id
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE u.tenant_id = :tenant AND u.status = 'active' AND p.code = :code
            UNION
            SELECT DISTINCT u.id
            FROM users u
            JOIN user_role_assignments ura ON ura.user_id = u.id
            JOIN role_permissions rp ON rp.role_id = ura.role_id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE u.tenant_id = :tenant AND u.status = 'active' AND p.code = :code
            SQL;

        /** @var list<string> $rows */
        $rows = $this->connection->fetchFirstColumn($sql, [
            'tenant' => $tenant->getId()->toRfc4122(),
            'code' => $permissionCode,
        ]);

        return \array_map(static fn (string $id): Uuid => Uuid::fromString($id), $rows);
    }
}
