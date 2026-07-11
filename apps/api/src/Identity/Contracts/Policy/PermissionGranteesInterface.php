<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Policy;

use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P2-02 (#2421) — reverse permission lookup: which ACTIVE users of
 * a tenant hold a permission code. The notification fan-out ("tell
 * every approver") and task auto-assignment (WFL-P4-02) are the
 * consumers. Complements {@see PermissionCheckerInterface} (forward
 * check by user id).
 */
interface PermissionGranteesInterface
{
    /**
     * @return list<Uuid> distinct ids of active users holding the code
     */
    public function userIdsWithPermission(Tenant $tenant, string $permissionCode): array;
}
