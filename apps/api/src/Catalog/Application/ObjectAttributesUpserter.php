<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Identity\Contracts\Policy\AttributePermissionReader;
use App\Shared\Domain\Tenant;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Map a flat `{attribute_code => value}` payload into `ObjectValue`
 * rows on a given `CatalogObject` (#45 / 0.4.5).
 *
 * IMP2-1.4 (#1466): the shared rule-set (canon normalisation, required,
 * per-type format validation, identifier uniqueness, scope routing) lives
 * in {@see ValueWriteCore} — this service is the thin per-request client
 * that maps violations to HTTP exceptions. The import path consumes the
 * same core through {@see BatchValueWriter}, which is what guarantees
 * "import validates exactly like the admin".
 *
 * Unknown attribute codes are silently dropped — see #45 for rationale.
 * The `AttributesIndexedSyncListener` (#38) keeps the denormalised cache
 * in sync through the same flush.
 */
final readonly class ObjectAttributesUpserter
{
    public function __construct(
        private AttributeRepositoryInterface $attributes,
        private ObjectValueRepositoryInterface $values,
        private ValueWriteCore $core,
        private AttributePermissionReader $attributePermissions,
        private CrossFieldRulesValidator $crossFieldRules,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param ?string              $locale    active locale scope (#1148); non-primary
     *                                        locale on a localizable attribute targets
     *                                        that locale row, otherwise the global row
     * @param ?Uuid                $channelId resolved active channel scope (#1154);
     *                                        applies to scopable attributes only
     */
    public function upsert(
        CatalogObject $object,
        array $payload,
        Provenance $provenance = Provenance::Manual,
        ?string $locale = null,
        ?Uuid $channelId = null,
    ): void {
        $tenant = $object->getTenant();
        if (!$tenant instanceof Tenant) {
            // Tenant is stamped on the parent during prePersist — for a
            // fresh aggregate built inside the same flush context the
            // listener has already run by the time this service is called.
            return;
        }

        // DP-07 (#2037, ADR-0025) — three phases: (1) the existing per-attribute
        // validation collects prepared writes instead of saving, (2) the
        // cross-field gate throws 422 BEFORE anything is saved, (3) persistence.
        // Guarantees a cross-field violation never leaves a half-written object.
        /** @var list<array{attribute: Attribute, envelope: array<string, mixed>, locale: ?string, channelId: ?Uuid}> $prepared */
        $prepared = [];

        foreach ($payload as $code => $rawValue) {
            if ('' === $code) {
                continue;
            }

            $attribute = $this->attributes->findByCode($code, $tenant);
            if (!$attribute instanceof Attribute) {
                continue;
            }

            // AUD-008 (#1578) — enforce the 3-state per-attribute permission
            // on the write path (PRD §3.5). A caller with `view`/`restricted`
            // on this attribute must not be able to mutate it. Only enforced
            // when a domain user is present — CLI backfills / system jobs
            // carry no token and write under their own authority.
            if ($this->attributePermissions->isAttributePermissionEnforced()
                && !$this->attributePermissions->canEditAttribute($attribute->getId())) {
                throw new AccessDeniedHttpException(\sprintf(
                    'You do not have permission to edit attribute "%s".',
                    $code,
                ));
            }

            [$targetLocale, $targetChannel] = $this->core->routeScope($attribute, $tenant, $locale, $channelId);

            $jsonbValue = $this->core->normalise($attribute->getType(), $rawValue);

            // #1350 — required attributes can never be explicitly emptied.
            $requiredViolation = $this->core->requiredViolation($attribute, $jsonbValue);
            if (null !== $requiredViolation) {
                throw new UnprocessableEntityHttpException($requiredViolation);
            }

            // #1216 / #1261 — per-type format + option membership.
            $formatViolations = $this->core->formatViolations($attribute, $jsonbValue);
            if ([] !== $formatViolations) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'Attribute "%s": %s',
                    $code,
                    $formatViolations[0],
                ));
            }

            // #1179 — identifier uniqueness pre-check for a clean 409.
            $duplicate = $this->core->duplicateIdentifier($object, $attribute, $jsonbValue);
            if (null !== $duplicate) {
                throw new ConflictHttpException(\sprintf(
                    'Identifier "%s" is already assigned to another %s.',
                    $duplicate,
                    $object->getObjectType()->getCode(),
                ));
            }

            $prepared[] = [
                'attribute' => $attribute,
                'envelope' => $jsonbValue,
                'locale' => $targetLocale,
                'channelId' => $targetChannel,
            ];
        }

        // Phase 2 — cross-field gate (ADR-0025): existing global rows
        // overlaid with the prepared writes; a violation aborts the whole
        // payload with 422 before the first save.
        $violations = $this->crossFieldRules->validateForUpsert($object, $prepared);
        if ([] !== $violations) {
            throw new UnprocessableEntityHttpException(implode(
                '; ',
                array_map(static fn ($violation): string => $violation->message, $violations),
            ));
        }

        // Phase 3 — persistence (unchanged semantics of the pre-DP-07 loop).
        foreach ($prepared as $write) {
            $existing = $this->values->findOneByScope($object, $write['attribute'], $write['channelId'], $write['locale']);
            if ($existing instanceof ObjectValue) {
                $existing->updateValue($write['envelope']);
                $existing->changeProvenance($provenance);
                $this->values->save($existing);

                continue;
            }

            $value = new ObjectValue(
                object: $object,
                attribute: $write['attribute'],
                value: $write['envelope'],
                provenance: $provenance,
                channelId: $write['channelId'],
                locale: $write['locale'],
            );
            $this->values->save($value);
        }
    }
}
