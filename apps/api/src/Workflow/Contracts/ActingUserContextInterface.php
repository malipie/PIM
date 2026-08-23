<?php

declare(strict_types=1);

namespace App\Workflow\Contracts;

use Symfony\Component\Uid\Uuid;

/**
 * Cross-context port for evaluating workflow guards as an initiating user
 * when no HTTP security token exists (workers, approvals and rollbacks).
 */
interface ActingUserContextInterface
{
    public function set(?Uuid $userId): void;

    public function userId(): ?Uuid;
}
