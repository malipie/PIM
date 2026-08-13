<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Rbac\PrdRoleTemplates;
use DateTimeInterface;

/**
 * RBAC-P5-005 (#695) — projection helper for the Settings → Roles
 * list. Discriminates system (`tenant IS NULL`) and custom roles via
 * the `type` field and surfaces the M2M user count alongside each
 * entry so the badge + count column in the UI render in one round
 * trip.
 */
final class RoleListResponseBuilder
{
    public const string TYPE_SYSTEM = 'system';
    public const string TYPE_CUSTOM = 'custom';

    /**
     * @param iterable<array{role: Role, user_count: int}> $rows
     *
     * @return list<array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     type: string,
     *     user_count: int,
     *     is_built_in: bool,
     *     created_at: string,
     *     permissions_count: int
     * }>
     */
    public function buildList(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $role = $row['role'];
            // #2837 — a role is built-in when it is global (platform scope)
            // OR comes from the PRD templates. Judging by globality alone
            // labelled every template role as user-made.
            $isBuiltIn = $role->isGlobal() || PrdRoleTemplates::isTemplateCode($role->getCode());
            $type = $isBuiltIn ? self::TYPE_SYSTEM : self::TYPE_CUSTOM;
            $out[] = [
                'id' => $role->getId()->toRfc4122(),
                'code' => $role->getCode(),
                'name' => $role->getName(),
                'type' => $type,
                'user_count' => $row['user_count'],
                'is_built_in' => $isBuiltIn,
                'created_at' => $role->getCreatedAt()->format(DateTimeInterface::ATOM),
                'permissions_count' => $role->getPermissions()->count(),
            ];
        }

        return $out;
    }
}
