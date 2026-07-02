<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Event;

use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P8-04 (#1986) — an agent run materialized a plan and stopped at
 * the approval gate ("the big batch is ready - come and look").
 *
 * Lives in Shared\Contracts (not the Agent module) so the CORE webhook
 * fan-out may subscribe without referencing App\Agent - the
 * removability gate (ADR-0024) stays clean: with the module gone,
 * nothing dispatches this event and the subscriber is a dead branch.
 */
final readonly class AgentRunAwaitingApproval implements DomainEvent
{
    public const string NAME = 'agent.run.awaiting_approval';

    public function __construct(
        public Uuid $runId,
        public string $intent,
        public int $affectedCount,
        private DateTimeImmutable $occurredOn = new DateTimeImmutable(),
    ) {
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function eventName(): string
    {
        return self::NAME;
    }

    public function aggregateId(): string
    {
        return $this->runId->toRfc4122();
    }
}
