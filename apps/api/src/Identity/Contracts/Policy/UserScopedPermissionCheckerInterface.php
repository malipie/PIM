<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Policy;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-02 (#1954) — fine-grained RBAC checks BY USER ID for
 * contexts with no security token (the agent loop runs in a Messenger
 * worker acting within the initiating user's permissions, ADR-0024 b).
 *
 * Complements {@see AttributePermissionReader} (current-token variant)
 * and {@see PermissionCheckerInterface} (coarse permission codes):
 * per-attribute 3-state resolution (PRD-PIM-rbac §3.5) and per-locale/
 * channel scope (§3.2). Implementations MUST fail closed for unknown
 * users.
 */
interface UserScopedPermissionCheckerInterface
{
    public function canViewAttribute(Uuid $userId, Uuid $attributeId): bool;

    public function canEditAttribute(Uuid $userId, Uuid $attributeId): bool;

    public function canEditLocale(Uuid $userId, string $locale): bool;

    public function canEditChannel(Uuid $userId, string $channel): bool;
}
