<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\Workflow\Contracts\ActingUserContextInterface;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P1-01 (#2415) — request-/message-scoped holder for "whose
 * permissions gate this transition" when there is no security token.
 *
 * HTTP requests resolve the actor from the token (CurrentUserProvider);
 * Messenger workers (bulk change_status, WFL-P1-05) act WITHIN the
 * initiating user's permissions (same model as the agent loop,
 * ADR-0024 b) and set this context before applying transitions.
 * When neither source yields a user the guard treats the caller as a
 * trusted system path (CLI, fixtures) — HTTP can never reach that
 * branch because every workflow endpoint requires authentication.
 */
final class ActingUserContext implements ActingUserContextInterface
{
    private ?Uuid $userId = null;

    public function set(?Uuid $userId): void
    {
        $this->userId = $userId;
    }

    public function userId(): ?Uuid
    {
        return $this->userId;
    }
}
