<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Event;

use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P8-04 (#1986) — an agent run reached a terminal outcome
 * (done / rejected / cancelled / error / rolled_back). Shared\Contracts
 * placement: see {@see AgentRunAwaitingApproval}.
 */
final readonly class AgentRunCompleted implements DomainEvent
{
    public const string NAME = 'agent.run.completed';

    public function __construct(
        public Uuid $runId,
        public string $intent,
        public string $outcome,
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
