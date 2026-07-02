<?php

declare(strict_types=1);

namespace App\Agent\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-01 (#1953) — technical step-by-step record of one tool call
 * (PRD 5.8): what the model called, with which arguments (secrets never
 * land here), whether the RBAC check ran, and a compact result summary.
 * DH Auditor (P3-07) is the accountability layer on top of this detail.
 */
class AgentToolCall implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private AgentRun $run;
    private string $toolName;
    private string $kind;

    /** @var array<string, mixed> */
    private array $arguments;

    /** @var array<string, mixed>|null */
    private ?array $resultSummary = null;

    private bool $rbacChecked = false;
    private ?int $durationMs = null;
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(AgentRun $run, string $toolName, string $kind, array $arguments)
    {
        $this->id = Uuid::v7();
        $this->run = $run;
        $this->toolName = $toolName;
        $this->kind = $kind;
        $this->arguments = $arguments;
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

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResultSummary(): ?array
    {
        return $this->resultSummary;
    }

    public function isRbacChecked(): bool
    {
        return $this->rbacChecked;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param array<string, mixed> $resultSummary
     */
    public function complete(array $resultSummary, bool $rbacChecked, int $durationMs): void
    {
        $this->resultSummary = $resultSummary;
        $this->rbacChecked = $rbacChecked;
        $this->durationMs = $durationMs;
    }
}
