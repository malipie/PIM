<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #1728 — mints a missing {@see Attribute} during an import run and attaches it
 * to the target {@see ObjectType}, so a mapped column whose attribute code does
 * not exist yet is created instead of silently dropped.
 *
 * Gated by the same opt-in as option auto-create (#1718,
 * {@see \App\Import\Domain\Entity\ImportSession::createMissingOptions()}): off =
 * the column stays unmapped (no value written), preserving prior behaviour.
 *
 * Works for ANY ObjectType (product, category, custom) — `ObjectTypeAttribute`
 * is kind-agnostic. Type is inferred conservatively from the first value
 * (numeric → Number, otherwise Text); select/multiselect are never guessed
 * (a fresh Text/Number attribute carries no options). The operator can migrate
 * the type afterwards.
 *
 * Minted attributes are cached by code per run (so a column mints once across
 * all chunks) and the cache is reset per run for worker-mode hygiene; a per-run
 * ceiling stops a mis-mapped column from minting unbounded attributes.
 */
final class AttributeAutoCreator
{
    /** Defensive ceiling: a mis-mapped column must not mint unbounded attributes. */
    private const int MAX_CREATES_PER_RUN = 200;

    /** @var array<string, Attribute> attribute code => minted attribute (this run) */
    private array $created = [];

    private int $createCount = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Drops the per-run cache + counter. The import handler calls this once at
     * the start of each run.
     */
    public function reset(): void
    {
        $this->created = [];
        $this->createCount = 0;
    }

    /**
     * Returns the attribute already minted this run for $code, or mints a new
     * one (type inferred from $sampleValue) attached to $objectType. Null once
     * the per-run ceiling is hit — the caller then skips the column.
     */
    public function ensure(string $code, ObjectType $objectType, string $sampleValue): ?Attribute
    {
        if (isset($this->created[$code])) {
            return $this->created[$code];
        }
        if ($this->createCount >= self::MAX_CREATES_PER_RUN) {
            return null;
        }

        $label = $this->humanize($code);
        $attribute = new Attribute($code, ['pl' => $label, 'en' => $label], $this->inferType($sampleValue));
        $this->em->persist($attribute);
        // Attach to the target ObjectType so the new attribute is part of its
        // editable model. sortOrder is parked high so minted attributes sort
        // after the curated ones.
        $this->em->persist(new ObjectTypeAttribute($objectType, $attribute, false, 1000 + $this->createCount));
        // One flush persists + tenant-stamps both rows and makes the attribute
        // visible to the value-write validation in the same row. Safe because
        // ImportObjectCreator builds writes BEFORE persisting the in-progress
        // object (the #1718 ordering), so no half-built object is flushed.
        $this->em->flush();

        $this->created[$code] = $attribute;
        ++$this->createCount;

        return $attribute;
    }

    private function inferType(string $sampleValue): AttributeType
    {
        $normalised = str_replace(',', '.', trim($sampleValue));

        return '' !== $normalised && is_numeric($normalised) ? AttributeType::Number : AttributeType::Text;
    }

    private function humanize(string $code): string
    {
        $words = trim(str_replace('_', ' ', $code));

        return '' === $words ? $code : ucfirst($words);
    }
}
