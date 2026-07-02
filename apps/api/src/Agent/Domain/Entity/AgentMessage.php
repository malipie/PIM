<?php

declare(strict_types=1);

namespace App\Agent\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-01 (#1953) — one conversation turn of a run, stored in the
 * Anthropic content shape (PRD 5.8). Cascade-deleted with the run.
 * Retention is per-user; hard delete rides the offboarding purge.
 */
class AgentMessage implements TenantScoped
{
    public const string ROLE_USER = 'user';
    public const string ROLE_ASSISTANT = 'assistant';
    public const string ROLE_TOOL = 'tool';

    private Uuid $id;
    private ?Tenant $tenant = null;
    private AgentRun $run;
    private string $role;

    /** @var array<int|string, mixed> */
    private array $content;

    private DateTimeImmutable $createdAt;

    /**
     * @param array<int|string, mixed> $content Anthropic message content shape
     */
    public function __construct(AgentRun $run, string $role, array $content)
    {
        if (!\in_array($role, [self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_TOOL], true)) {
            throw new LogicException(\sprintf('Unknown agent message role "%s".', $role));
        }

        $this->id = Uuid::v7();
        $this->run = $run;
        $this->role = $role;
        $this->content = $content;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function assignTenant(Tenant $tenant): void
    {
        if (null !== $this->tenant) {
            throw new LogicException('Tenant already assigned.');
        }
        $this->tenant = $tenant;
    }

    public function getRun(): AgentRun
    {
        return $this->run;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getContent(): array
    {
        return $this->content;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
