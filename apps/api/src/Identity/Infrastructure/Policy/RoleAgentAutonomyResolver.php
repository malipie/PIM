<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Policy;

use App\Identity\Contracts\Policy\AgentAutonomyResolverInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P8-05 (#1987) — resolves the user's agent-autonomy level from
 * their assigned roles (most permissive wins, like permissions). A
 * user with no roles gets 'off' - no role, no agent.
 */
final readonly class RoleAgentAutonomyResolver implements AgentAutonomyResolverInterface
{
    private const array RANK = ['off' => 0, 'read_only' => 1, 'propose' => 2];

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function autonomyForUser(Uuid $userId): string
    {
        // tenant-safe: assignment rows are reachable only through the
        // caller's user id; RLS is the backstop. ADR-0034 (#2832) — reads
        // the assignment table, so a role granted by invitation counts too.
        $levels = $this->connection->fetchFirstColumn(
            'SELECT r.agent_autonomy FROM roles r JOIN user_role_assignments ur ON ur.role_id = r.id WHERE ur.user_id = :user',
            ['user' => $userId->toRfc4122()],
        );

        $best = 'off';
        foreach ($levels as $level) {
            if (\is_string($level) && (self::RANK[$level] ?? -1) > self::RANK[$best]) {
                $best = $level;
            }
        }

        return \in_array($best, ['off', 'read_only', 'propose'], true) ? $best : 'off';
    }
}
