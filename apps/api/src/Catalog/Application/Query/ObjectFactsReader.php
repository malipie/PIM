<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\ObjectValueLocaleOverlay;
use App\Catalog\Contracts\Query\ObjectFacts;
use App\Catalog\Contracts\Query\ObjectFactsPort;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P2-01 (#2331) — Catalog-side implementation of the grounding
 * fact sheet. The scoped reading reuses {@see ObjectValueLocaleOverlay}
 * (the same locale-first resolution the read API serves, on a detached
 * clone — the identity map never sees the overlay), sibling-locale
 * readings come from the canonical ObjectValue rows. Read-only by
 * construction: no persist, no flush, no cache writes.
 */
final readonly class ObjectFactsReader implements ObjectFactsPort
{
    /** Envelope keys that are audit metadata, not product facts. */
    private const array PROVENANCE_KEYS = ['provenance', 'provenance_meta'];

    public function __construct(
        private EntityManagerInterface $em,
        private ObjectValueLocaleOverlay $overlay,
        private ObjectValueRepositoryInterface $values,
    ) {
    }

    public function facts(Uuid $objectId, array $attributeCodes, ?string $locale = null, ?string $channel = null): ObjectFacts
    {
        $object = $this->em->find(CatalogObject::class, $objectId);
        if (!$object instanceof CatalogObject) {
            throw new InvalidArgumentException(\sprintf('Unknown object "%s".', $objectId->toRfc4122()));
        }

        $scoped = $this->overlay->apply($object, $locale, $channel);
        $indexed = $scoped->getAttributesIndexed();

        $resolved = [];
        $missing = [];
        foreach ($attributeCodes as $code) {
            $slot = $indexed[$code] ?? null;
            if (\is_array($slot) && [] !== $envelope = $this->stripProvenance($slot)) {
                $resolved[$code] = $envelope;
            } else {
                $missing[] = $code;
            }
        }

        return new ObjectFacts(
            objectId: $object->getId(),
            objectTypeId: $object->getObjectType()->getId(),
            values: $resolved,
            missingCodes: $missing,
            siblingLocales: $this->siblingLocales($object, $attributeCodes, $locale),
        );
    }

    /**
     * Readings of the requested codes in OTHER locales — source facts
     * for translation-flavoured generation (plan §3a pkt 3).
     *
     * @param list<string> $attributeCodes
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function siblingLocales(CatalogObject $object, array $attributeCodes, ?string $locale): array
    {
        $wanted = array_flip($attributeCodes);
        $siblings = [];
        foreach ($this->values->findByObject($object) as $value) {
            $rowLocale = $value->getLocale();
            if (null === $rowLocale || $rowLocale === $locale || null !== $value->getChannelId()) {
                continue;
            }
            $code = $value->getAttribute()->getCode();
            if (!isset($wanted[$code])) {
                continue;
            }
            $envelope = $this->stripProvenance($value->getValue());
            if ([] !== $envelope) {
                $siblings[$code][$rowLocale] = $envelope;
            }
        }

        return $siblings;
    }

    /**
     * @param array<mixed> $slot
     *
     * @return array<string, mixed>
     */
    private function stripProvenance(array $slot): array
    {
        $clean = [];
        foreach ($slot as $key => $value) {
            if (\is_string($key) && !\in_array($key, self::PROVENANCE_KEYS, true)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
