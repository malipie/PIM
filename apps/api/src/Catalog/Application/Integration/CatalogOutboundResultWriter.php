<?php

declare(strict_types=1);

namespace App\Catalog\Application\Integration;

use App\Catalog\Application\BatchValueWriter;
use App\Catalog\Contracts\Integration\OutboundResultWriter;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Catalog-side implementation of the outbound result-capture seam (#2636).
 *
 * Mirrors {@see CatalogInboundRecordWriter}: reuses {@see BatchValueWriter}
 * (the IMP2 write core) with `Provenance::Integration`, so the captured remote
 * id shows up in the product form with an integration badge and flows through
 * the synchronous attributes-indexed listener on the runner's flush. Writing an
 * unchanged value is a no-op (no domain event, no version bump).
 */
final readonly class CatalogOutboundResultWriter implements OutboundResultWriter
{
    public function __construct(
        private EntityManagerInterface $em,
        private AttributeRepositoryInterface $attributes,
        private BatchValueWriter $valueWriter,
        private TenantContext $tenantContext,
    ) {
    }

    public function writeValue(Uuid $objectId, string $attributeCode, string $value): bool
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant || '' === trim($value)) {
            return false;
        }

        $attribute = $this->attributes->findByCode($attributeCode, $tenant);
        if (null === $attribute) {
            return false;
        }

        $object = $this->em->find(CatalogObject::class, $objectId->toRfc4122());
        if (!$object instanceof CatalogObject) {
            return false;
        }

        $result = $this->valueWriter->writeMany(
            $object,
            [[
                'attribute' => $attribute,
                'envelope' => ['value' => $value],
                'locale' => null,
                'channelId' => null,
            ]],
            Provenance::Integration,
        );

        return $result['changed'] > 0;
    }
}
