<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\RelationCardinality;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * #2314 — bulk value actions on a relation-type attribute write link rows
 * (object_relations) through the relation service instead of polluting
 * attributesIndexed, and rollback replays the recorded target-id list.
 */
final class BulkRelationActionsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function bulkRelationLifecycleSetAppendRemoveRollbackClear(): void
    {
        $attrCode = 'bulk_rel_'.substr(bin2hex(random_bytes(4)), 0, 6);
        $this->seedRelationAttribute($attrCode);
        $source1 = $this->seedProduct('REL-SRC-1-'.$attrCode);
        $source2 = $this->seedProduct('REL-SRC-2-'.$attrCode);
        $target1 = $this->seedProduct('REL-TGT-1-'.$attrCode);
        $target2 = $this->seedProduct('REL-TGT-2-'.$attrCode);
        $sourceIds = [$source1->getId()->toRfc4122(), $source2->getId()->toRfc4122()];
        $t1 = $target1->getId()->toRfc4122();
        $t2 = $target2->getId()->toRfc4122();

        // set_attribute → replace with [t1] on both sources.
        $set = $this->dispatch('set_attribute', $sourceIds, ['attr' => $attrCode, 'value' => [$t1]]);
        self::assertSame(2, $set['success_count']);
        self::assertSame([$t1], $this->relationTargets($sourceIds[0], $attrCode));
        self::assertSame([$t1], $this->relationTargets($sourceIds[1], $attrCode));

        // append_value → [t1, t2].
        $append = $this->dispatch('append_value', $sourceIds, ['attr' => $attrCode, 'value' => [$t2]]);
        self::assertSame(2, $append['success_count']);
        self::assertSame([$t1, $t2], $this->relationTargets($sourceIds[0], $attrCode));

        // remove_value → [t2].
        $remove = $this->dispatch('remove_value', $sourceIds, ['attr' => $attrCode, 'value' => [$t1]]);
        self::assertSame(2, $remove['success_count']);
        self::assertSame([$t2], $this->relationTargets($sourceIds[0], $attrCode));

        // rollback of the remove session → back to [t1, t2].
        $removeSessionId = $remove['session_id'] ?? null;
        \assert(\is_string($removeSessionId));
        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/bulk-sessions/%s/rollback', $removeSessionId));
        self::assertResponseIsSuccessful();
        self::assertSame([$t1, $t2], $this->relationTargets($sourceIds[0], $attrCode));

        // clear_attribute → [].
        $clear = $this->dispatch('clear_attribute', $sourceIds, ['attr' => $attrCode]);
        self::assertSame(2, $clear['success_count']);
        self::assertSame([], $this->relationTargets($sourceIds[0], $attrCode));

        // attributesIndexed must stay free of raw relation ids.
        $this->em()->clear();
        $fresh = $this->em()->find(CatalogObject::class, $source1->getId());
        self::assertInstanceOf(CatalogObject::class, $fresh);
        self::assertArrayNotHasKey($attrCode, $fresh->getAttributesIndexed());
    }

    #[Test]
    public function bulkRelationRejectsNonUuidValue(): void
    {
        $attrCode = 'bulk_rel_'.substr(bin2hex(random_bytes(4)), 0, 6);
        $this->seedRelationAttribute($attrCode);
        $source = $this->seedProduct('REL-BAD-'.$attrCode);

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/products/bulk-actions/set_attribute', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'target_ids' => [$source->getId()->toRfc4122()],
                'payload' => ['attr' => $attrCode, 'value' => 'not-a-uuid'],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param list<string>         $targetIds
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function dispatch(string $action, array $targetIds, array $payload): array
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/products/bulk-actions/'.$action, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'target_ids' => $targetIds,
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(200, $response->getStatusCode(), $action.' must answer 200');

        /** @var array<string, mixed> $decoded */
        $decoded = $response->toArray();

        return $decoded;
    }

    /**
     * Read the persisted target ids for (source, attribute) through the
     * public relations endpoint — the same read path the detail tab uses.
     *
     * @return list<string>
     */
    private function relationTargets(string $sourceId, string $attrCode): array
    {
        $client = $this->authenticatedClient();
        $body = $client->request('GET', \sprintf('/api/objects/%s/relations', $sourceId))->toArray();
        $groups = $body['relationAttributes'] ?? [];
        \assert(\is_array($groups));
        foreach ($groups as $group) {
            \assert(\is_array($group));
            $attribute = $group['attribute'] ?? [];
            \assert(\is_array($attribute));
            if (($attribute['code'] ?? null) !== $attrCode) {
                continue;
            }
            $relations = $group['relations'] ?? [];
            \assert(\is_array($relations));
            $ids = [];
            foreach ($relations as $row) {
                \assert(\is_array($row));
                $target = $row['targetObjectId'] ?? null;
                if (\is_string($target)) {
                    $ids[] = $target;
                }
            }

            return $ids;
        }

        return [];
    }

    private function seedRelationAttribute(string $code): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $attribute = new Attribute($code, ['en' => 'Bulk relation'], AttributeType::Relation);
        $attribute->setRelationTargetObjectTypeIds([$productType->getId()->toRfc4122()]);
        $attribute->setRelationCardinality(RelationCardinality::Many);

        $em = $this->em();
        $em->persist($attribute);
        $em->persist(new ObjectTypeAttribute($productType, $attribute, false, 90));
        $em->flush();

        $tenantContext->clear();
    }

    private function seedProduct(string $code): CatalogObject
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $object = new CatalogObject($type, $code);
        $em = $this->em();
        $em->persist($object);
        $em->flush();

        $tenantContext->clear();

        return $object;
    }
}
