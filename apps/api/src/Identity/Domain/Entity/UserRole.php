<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Per-user role assignment with scope (locale + channel + attribute_groups).
 *
 * Promotes the simple M2M `users <-> roles` junction into a first-class entity
 * so the assignment can carry scope restrictions (PRD-PIM-rbac §3.4): a user
 * can hold the `marketing` role but only for `pl` locale and the `shopify`
 * channel, or restricted to a subset of attribute groups.
 *
 * Scope semantics (resolved by Phase 3 ProductVoter / AttributePermissionPolicy):
 *  - empty array `[]` means "no restriction" (broad scope — role applies to all
 *    locales / channels / attribute groups). NULL is not used to keep array
 *    semantics consistent in `in_array()` checks on the resolver hot path.
 *  - non-empty array means the role is restricted to listed values; permission
 *    checks intersect this set with the resource scope before granting.
 *
 * ADR-0034 (#2832) — this table is now the ONLY record of who holds which
 * role. The Sprint-0 `user_roles` M2M is gone from the model: it had no
 * scope columns, so a role granted for one locale was silently widened by
 * its own duplicate row there (AUD-029), and a user created through an
 * invitation showed up as "no roles" anywhere that read the old junction.
 *
 * The user and role are associations rather than bare UUIDs so `User` can
 * own its assignments (cascade persist through `User::addRole()`) and read
 * a role code without a second query.
 */
class UserRole
{
    private Uuid $id;

    private User $user;

    private Role $role;

    /**
     * Locale scope (`['pl', 'en']`). Empty array means "all locales".
     *
     * @var list<string>
     */
    private array $localeScope;

    /**
     * Channel scope (`['shopify', 'baselinker']`). Empty array means "all channels".
     *
     * @var list<string>
     */
    private array $channelScope;

    /**
     * Attribute-group scope (UUID list of AttributeGroup IDs). Empty array
     * means "all attribute groups".
     *
     * @var list<string>
     */
    private array $attributeGroupScope;

    private DateTimeImmutable $assignedAt;

    /**
     * @param list<string> $localeScope
     * @param list<string> $channelScope
     * @param list<string> $attributeGroupScope
     */
    public function __construct(
        User $user,
        Role $role,
        array $localeScope = [],
        array $channelScope = [],
        array $attributeGroupScope = [],
        ?Uuid $id = null,
        ?DateTimeImmutable $assignedAt = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->user = $user;
        $this->role = $role;
        $this->localeScope = $localeScope;
        $this->channelScope = $channelScope;
        $this->attributeGroupScope = $attributeGroupScope;
        $this->assignedAt = $assignedAt ?? new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getUserId(): Uuid
    {
        return $this->user->getId();
    }

    public function getRoleId(): Uuid
    {
        return $this->role->getId();
    }

    /**
     * @return list<string>
     */
    public function getLocaleScope(): array
    {
        return $this->localeScope;
    }

    /**
     * @return list<string>
     */
    public function getChannelScope(): array
    {
        return $this->channelScope;
    }

    /**
     * @return list<string>
     */
    public function getAttributeGroupScope(): array
    {
        return $this->attributeGroupScope;
    }

    public function getAssignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function hasLocaleRestriction(): bool
    {
        return [] !== $this->localeScope;
    }

    public function hasChannelRestriction(): bool
    {
        return [] !== $this->channelScope;
    }

    public function hasAttributeGroupRestriction(): bool
    {
        return [] !== $this->attributeGroupScope;
    }

    public function appliesToLocale(string $locale): bool
    {
        return [] === $this->localeScope || \in_array($locale, $this->localeScope, true);
    }

    public function appliesToChannel(string $channel): bool
    {
        return [] === $this->channelScope || \in_array($channel, $this->channelScope, true);
    }

    public function appliesToAttributeGroup(string $attributeGroupId): bool
    {
        return [] === $this->attributeGroupScope || \in_array($attributeGroupId, $this->attributeGroupScope, true);
    }
}
