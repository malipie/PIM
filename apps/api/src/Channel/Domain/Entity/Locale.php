<?php

declare(strict_types=1);

namespace App\Channel\Domain\Entity;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * BCP-47-shaped locale code (`pl_PL`, `en_US`, …) shared across tenants.
 *
 * Locales are global infrastructure rows — every tenant references the
 * same `pl_PL` row rather than carrying its own copy. The seeded set in
 * the migration covers the ISO 639-1 + ISO 3166 catalog used by Harmon
 * (~45 entries today, 14 of them `is_popular=true` for the CEE+DACH
 * dropdown shortlist).
 *
 * No TenantScoped: the table sits on the audit allowlist as global
 * infrastructure, like `permissions` and the global RBAC roles.
 */
class Locale
{
    private Uuid $id;

    #[Assert\NotBlank]
    #[Assert\Length(max: 16)]
    private string $code;

    private string $label;

    private string $language = '';

    private ?string $region = null;

    /**
     * @var array<string,string> two-letter UI-locale ⇒ native display name,
     *                           e.g. {"pl":"Polski (Polska)","en":"Polish (Poland)"}.
     */
    private array $displayName = [];

    private bool $isPopular = false;

    /**
     * @param array<string,string> $displayName
     */
    public function __construct(
        string $code,
        string $label,
        ?Uuid $id = null,
        string $language = '',
        ?string $region = null,
        array $displayName = [],
        bool $isPopular = false,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->label = $label;
        $this->language = $language;
        $this->region = $region;
        $this->displayName = $displayName;
        $this->isPopular = $isPopular;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function rename(string $label): void
    {
        $this->label = $label;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    /**
     * @return array<string,string>
     */
    public function getDisplayName(): array
    {
        return $this->displayName;
    }

    public function isPopular(): bool
    {
        return $this->isPopular;
    }

    /**
     * @param array<string,string> $displayName
     */
    public function updateMetadata(string $language, ?string $region, array $displayName, bool $isPopular): void
    {
        $this->language = $language;
        $this->region = $region;
        $this->displayName = $displayName;
        $this->isPopular = $isPopular;
    }
}
