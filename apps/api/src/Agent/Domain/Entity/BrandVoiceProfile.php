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
 * AICG-P1-02 (#2328, ADR-0030 decision B) — the tenant's brand voice:
 * tone, imposed glossary, banned words and good/bad examples, injected
 * into the content-generation system prompt by AgentSystemPromptBuilder
 * (AICG-P2-03). Consistency over creativity (plan §5 rule 6).
 *
 * Lives in the removable Agent BC: a voice profile is useless without
 * the LLM engine (ADR-0030 decision 3). At most one profile per tenant
 * carries `is_default` — enforced by a partial unique index; flipping
 * the default is a repository-level swap (AICG-P1-03), not an entity
 * concern.
 *
 * JSONB shapes (plan §6.4):
 *   glossary: [{term: string, use: string}]
 *   examples: [{good: string, bad: string}]
 *   banned_words: [string]
 */
class BrandVoiceProfile implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private string $name;
    private string $tone;
    /** @var list<array{term: string, use: string}> */
    private array $glossary = [];
    /** @var list<string> */
    private array $bannedWords = [];
    /** @var list<array{good: string, bad: string}> */
    private array $examples = [];
    private bool $isDefault = false;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<mixed> $glossary    validated to {term, use} string pairs
     * @param array<mixed> $bannedWords validated to non-empty strings
     * @param array<mixed> $examples    validated to {good, bad} string pairs
     */
    public function __construct(
        string $name,
        string $tone,
        array $glossary = [],
        array $bannedWords = [],
        array $examples = [],
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->name = $name;
        $this->tone = $tone;
        $this->glossary = self::guardGlossary($glossary);
        $this->bannedWords = self::guardBannedWords($bannedWords);
        $this->examples = self::guardExamples($examples);
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

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getTone(): string
    {
        return $this->tone;
    }

    public function updateTone(string $tone): void
    {
        $this->tone = $tone;
        $this->touch();
    }

    /**
     * @return list<array{term: string, use: string}>
     */
    public function getGlossary(): array
    {
        return $this->glossary;
    }

    /**
     * @param array<mixed> $glossary validated to {term, use} string pairs
     */
    public function updateGlossary(array $glossary): void
    {
        $this->glossary = self::guardGlossary($glossary);
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function getBannedWords(): array
    {
        return $this->bannedWords;
    }

    /**
     * @param array<mixed> $bannedWords validated to non-empty strings
     */
    public function updateBannedWords(array $bannedWords): void
    {
        $this->bannedWords = self::guardBannedWords($bannedWords);
        $this->touch();
    }

    /**
     * @return list<array{good: string, bad: string}>
     */
    public function getExamples(): array
    {
        return $this->examples;
    }

    /**
     * @param array<mixed> $examples validated to {good, bad} string pairs
     */
    public function updateExamples(array $examples): void
    {
        $this->examples = self::guardExamples($examples);
        $this->touch();
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    /**
     * @internal the default swap (clear previous, set new) is transactional
     * repository work — callers must never leave two defaults per tenant;
     * the partial unique index is the DB backstop
     */
    public function markDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
        $this->touch();
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
     * @param array<mixed> $glossary
     *
     * @return list<array{term: string, use: string}>
     */
    private static function guardGlossary(array $glossary): array
    {
        $clean = [];
        foreach ($glossary as $entry) {
            $term = \is_array($entry) ? ($entry['term'] ?? null) : null;
            $use = \is_array($entry) ? ($entry['use'] ?? null) : null;
            if (!\is_string($term) || '' === $term || !\is_string($use)) {
                throw new InvalidArgumentException('glossary must be a list of {term, use} string pairs.');
            }
            $clean[] = ['term' => $term, 'use' => $use];
        }

        return $clean;
    }

    /**
     * @param array<mixed> $bannedWords
     *
     * @return list<string>
     */
    private static function guardBannedWords(array $bannedWords): array
    {
        $clean = [];
        foreach ($bannedWords as $word) {
            if (!\is_string($word) || '' === $word) {
                throw new InvalidArgumentException('banned_words must be a list of non-empty strings.');
            }
            $clean[] = $word;
        }

        return $clean;
    }

    /**
     * @param array<mixed> $examples
     *
     * @return list<array{good: string, bad: string}>
     */
    private static function guardExamples(array $examples): array
    {
        $clean = [];
        foreach ($examples as $entry) {
            $good = \is_array($entry) ? ($entry['good'] ?? null) : null;
            $bad = \is_array($entry) ? ($entry['bad'] ?? null) : null;
            if (!\is_string($good) || !\is_string($bad)) {
                throw new InvalidArgumentException('examples must be a list of {good, bad} string pairs.');
            }
            $clean[] = ['good' => $good, 'bad' => $bad];
        }

        return $clean;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
