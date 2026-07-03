<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC role aggregating a set of permissions.
 *
 * `tenant` NULL means the role is global (built-in seeder roles in #27 —
 * super_admin / catalog_manager / integration_manager / viewer). Per-tenant
 * custom roles land in Faza 2+ once admin UI for role management arrives.
 */
class Role
{
    private Uuid $id;

    private ?Tenant $tenant;

    private string $code;

    private string $name;

    /**
     * Role editor polish (marathon-3) — multi-line explanation visible
     * in the custom-role builder (PRD-PIM-rbac §5.3 mockup). NULL on
     * seeded system roles for backwards compatibility.
     */
    private ?string $description = null;

    /**
     * RBAC-P5-008 (#698) — when set, the ObjectType creation flow
     * (epik 0.4) auto-grants the new type's `view + edit` permissions
     * to this role at flush time. False on every seeded role so the
     * default stays explicit-grant.
     */
    private bool $autoGrantNewObjectTypes;

    /**
     * AGENT-P8-05 (#1987) — how much the agent may DO for holders of
     * this role: 'off' (no agent), 'read_only' (grounding tools only),
     * 'propose' (default - full tool surface; write/schema still always
     * go through the approval gate).
     */
    private string $agentAutonomy = 'propose';

    private DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, Permission>
     */
    private Collection $permissions;

    public function __construct(
        string $code,
        string $name,
        ?Tenant $tenant = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->name = $name;
        $this->tenant = $tenant;
        $this->autoGrantNewObjectTypes = false;
        $this->createdAt = new DateTimeImmutable();
        $this->permissions = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        if (null === $description) {
            $this->description = null;

            return;
        }
        $trimmed = trim($description);
        $this->description = '' === $trimmed ? null : $trimmed;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function isGlobal(): bool
    {
        return null === $this->tenant;
    }

    public function getAgentAutonomy(): string
    {
        return $this->agentAutonomy;
    }

    public function setAgentAutonomy(string $autonomy): void
    {
        if (!\in_array($autonomy, ['off', 'read_only', 'propose'], true)) {
            throw new InvalidArgumentException(\sprintf('Unknown agent autonomy "%s" (off|read_only|propose).', $autonomy));
        }
        $this->agentAutonomy = $autonomy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Permission>
     */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function isAutoGrantNewObjectTypes(): bool
    {
        return $this->autoGrantNewObjectTypes;
    }

    public function setAutoGrantNewObjectTypes(bool $enabled): void
    {
        $this->autoGrantNewObjectTypes = $enabled;
    }

    public function grantPermission(Permission $permission): void
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }
    }

    public function revokePermission(Permission $permission): void
    {
        $this->permissions->removeElement($permission);
    }
}
