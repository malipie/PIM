<?php

declare(strict_types=1);

namespace App\Agent\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P1-01 (#2327, ADR-0030) — a reusable "how to write" configuration
 * for the content-generation tools (the Akeneo *AI Configurations*
 * counterpart): which attribute the generated text targets, which
 * attribute codes feed the grounding as facts, and the constraints the
 * output must satisfy (format, length, SEO rules).
 *
 * Lives in the removable Agent BC on purpose: a recipe is useless
 * without the LLM engine, so it disappears with `rm -rf src/Agent`
 * (ADR-0030 decision 3). Cross-BC references (`objectTypeId`,
 * `brandVoiceId`) are bare UUIDs per ADR-0015 — never Doctrine
 * associations into Catalog/Agent aggregates.
 *
 * `constraints` shape (plan §6.3):
 *   {format: 'plain'|'html', max_len?: int, seo?: {keyword?, title_len?, meta_len?}}
 * The guard only pins the slots the engine relies on (format enum,
 * source attribute codes as strings); SEO rules stay free-form for the
 * SeoRulesValidator (AICG-P4-01) — decision C: rules live in the
 * recipe, not the schema.
 */
class ContentRecipe implements TenantScoped
{
    public const string FORMAT_PLAIN = 'plain';
    public const string FORMAT_HTML = 'html';
    private const array ALLOWED_FORMATS = [self::FORMAT_PLAIN, self::FORMAT_HTML];

    private Uuid $id;
    private ?Tenant $tenant = null;
    private string $code;
    private string $name;
    private ?Uuid $objectTypeId;
    /**
     * Optional scope narrowing (category / channel), free-form envelope.
     *
     * @var array<string, mixed>
     */
    private array $appliesTo = [];
    private string $targetAttribute;
    /** @var list<string> attribute codes fed to the grounding as facts */
    private array $sourceAttributes = [];
    /** @var array<string, mixed> */
    private array $constraints = [];
    private ?string $toneHint = null;
    private ?Uuid $brandVoiceId = null;
    private bool $isBuiltIn = false;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<mixed>         $sourceAttributes attribute codes — validated to non-empty strings and reindexed
     * @param array<string, mixed> $constraints
     */
    public function __construct(
        string $code,
        string $name,
        string $targetAttribute,
        array $sourceAttributes = [],
        array $constraints = [],
        ?Uuid $objectTypeId = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->name = $name;
        $this->targetAttribute = $targetAttribute;
        $this->sourceAttributes = self::guardSourceAttributes($sourceAttributes);
        $this->constraints = self::guardConstraints($constraints);
        $this->objectTypeId = $objectTypeId;
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
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
        $this->touch();
    }

    public function getObjectTypeId(): ?Uuid
    {
        return $this->objectTypeId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAppliesTo(): array
    {
        return $this->appliesTo;
    }

    /**
     * @param array<string, mixed> $appliesTo
     */
    public function updateAppliesTo(array $appliesTo): void
    {
        $this->appliesTo = $appliesTo;
        $this->touch();
    }

    public function getTargetAttribute(): string
    {
        return $this->targetAttribute;
    }

    public function retarget(string $targetAttribute): void
    {
        $this->targetAttribute = $targetAttribute;
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function getSourceAttributes(): array
    {
        return $this->sourceAttributes;
    }

    /**
     * @param array<mixed> $sourceAttributes attribute codes — validated to non-empty strings and reindexed
     */
    public function updateSourceAttributes(array $sourceAttributes): void
    {
        $this->sourceAttributes = self::guardSourceAttributes($sourceAttributes);
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    /**
     * @param array<string, mixed> $constraints
     */
    public function updateConstraints(array $constraints): void
    {
        $this->constraints = self::guardConstraints($constraints);
        $this->touch();
    }

    public function getToneHint(): ?string
    {
        return $this->toneHint;
    }

    public function updateToneHint(?string $toneHint): void
    {
        $this->toneHint = $toneHint;
        $this->touch();
    }

    public function getBrandVoiceId(): ?Uuid
    {
        return $this->brandVoiceId;
    }

    public function attachBrandVoice(?Uuid $brandVoiceId): void
    {
        $this->brandVoiceId = $brandVoiceId;
        $this->touch();
    }

    public function isBuiltIn(): bool
    {
        return $this->isBuiltIn;
    }

    /**
     * @internal seeders only (AICG-P1-04) — built-in recipes are cloned,
     * never edited in place; there is deliberately no way back to false
     */
    public function markBuiltIn(): void
    {
        $this->isBuiltIn = true;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param array<mixed> $sourceAttributes
     *
     * @return list<string>
     */
    private static function guardSourceAttributes(array $sourceAttributes): array
    {
        foreach ($sourceAttributes as $code) {
            if (!\is_string($code) || '' === $code) {
                throw new InvalidArgumentException('source_attributes must be a list of non-empty attribute codes.');
            }
        }

        return array_values($sourceAttributes);
    }

    /**
     * @param array<string, mixed> $constraints
     *
     * @return array<string, mixed>
     */
    private static function guardConstraints(array $constraints): array
    {
        if (\array_key_exists('format', $constraints) && !\in_array($constraints['format'], self::ALLOWED_FORMATS, true)) {
            throw new InvalidArgumentException(\sprintf(
                'constraints.format must be one of [%s].',
                implode(', ', self::ALLOWED_FORMATS),
            ));
        }
        if (\array_key_exists('max_len', $constraints) && (!\is_int($constraints['max_len']) || $constraints['max_len'] < 1)) {
            throw new InvalidArgumentException('constraints.max_len must be a positive integer.');
        }
        if (\array_key_exists('seo', $constraints) && !\is_array($constraints['seo'])) {
            throw new InvalidArgumentException('constraints.seo must be an object.');
        }

        return $constraints;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
