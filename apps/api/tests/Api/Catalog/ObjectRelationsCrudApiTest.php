<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInProductRelationAttributesSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\RelationCardinality;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR-014 / MOD-06 (#898) — CRUD smoke for `/api/objects/{id}/relations`.
 */
final class ObjectRelationsCrudApiTest extends CatalogApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        // Seed 5 built-in relation attrs on Product (cross_sell, up_sell, …)
        // so the controller has something to look up by code.
        self::getContainer()->get(BuiltInProductRelationAttributesSeeder::class)->seed($tenant);
    }

    #[Test]
    public function putReplacesRelationsForAttribute(): void
    {
        $client = $this->authenticatedClient();

        $source = $this->makeProduct('SOURCE-A');
        $a = $this->makeProduct('TARGET-A');
        $b = $this->makeProduct('TARGET-B');

        $resp = $client->request('PUT', '/api/objects/'.$source->getId()->toRfc4122().'/relations/cross_sell', [
            'json' => [
                'targets' => [
                    ['id' => $a->getId()->toRfc4122()],
                    ['id' => $b->getId()->toRfc4122()],
                ],
            ],
        ]);
        self::assertSame(204, $resp->getStatusCode(), $resp->getContent(false));

        $listResp = $client->request('GET', '/api/objects/'.$source->getId()->toRfc4122().'/relations');
        self::assertSame(200, $listResp->getStatusCode());
        $body = $listResp->toArray();
        self::assertSame($source->getId()->toRfc4122(), $body['sourceObjectId']);

        /** @var list<array<string, mixed>> $relationAttributes */
        $relationAttributes = $body['relationAttributes'];
        $crossSell = $this->groupForCode($relationAttributes, 'cross_sell');
        /** @var list<array{targetObjectId: string, position: int}> $relations */
        $relations = $crossSell['relations'];
        self::assertCount(2, $relations);
        self::assertSame($a->getId()->toRfc4122(), $relations[0]['targetObjectId']);
        self::assertSame($b->getId()->toRfc4122(), $relations[1]['targetObjectId']);
    }

    #[Test]
    public function deleteRemovesSingleRelation(): void
    {
        $client = $this->authenticatedClient();

        $source = $this->makeProduct('SRC-DEL');
        $target = $this->makeProduct('TGT-DEL');

        // Seed one relation first.
        $client->request('PUT', '/api/objects/'.$source->getId()->toRfc4122().'/relations/related', [
            'json' => ['targets' => [['id' => $target->getId()->toRfc4122()]]],
        ]);
        $deleteResp = $client->request(
            'DELETE',
            \sprintf(
                '/api/objects/%s/relations/related/%s',
                $source->getId()->toRfc4122(),
                $target->getId()->toRfc4122(),
            ),
        );
        self::assertSame(204, $deleteResp->getStatusCode(), $deleteResp->getContent(false));

        $listResp = $client->request('GET', '/api/objects/'.$source->getId()->toRfc4122().'/relations');
        /** @var list<array<string, mixed>> $relationAttributes */
        $relationAttributes = $listResp->toArray()['relationAttributes'];
        $related = $this->groupForCode($relationAttributes, 'related');
        self::assertSame([], $related['relations']);
    }

    #[Test]
    public function putRejectsCrossTenantTarget(): void
    {
        $client = $this->authenticatedClient();
        $source = $this->makeProduct('SRC-XTEN');

        // The Acme tenant is seeded by CatalogApiTestCase as a separate
        // shell; its built-in Product ObjectType is reachable only
        // through TenantContext switching. Use a syntactically-valid but
        // unknown UUID — same effect (404 inside the service).
        $client->request('PUT', '/api/objects/'.$source->getId()->toRfc4122().'/relations/cross_sell', [
            'json' => ['targets' => [['id' => '01234567-1234-7000-8000-000000000000']]],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * #1362 — a relation attribute attached to the ObjectType through an
     * AttributeGroup (the normal modeling flow) must surface in
     * `/relations` so the inline editor renders the picker instead of
     * "Atrybut nie jest jeszcze przypięty do ObjectType". The previous
     * implementation only looked at the direct `object_type_attributes`
     * junction and returned an empty list for group-attached relations.
     */
    #[Test]
    public function listIncludesRelationAttributeAttachedViaGroup(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $group = new AttributeGroup('rel_via_group', ['en' => 'Relations via group']);
        $attribute = new Attribute(
            'rel_via_group_attr',
            ['en' => 'Relation via group'],
            AttributeType::Relation,
        );
        $attribute->setRelationCardinality(RelationCardinality::Many);
        $em->persist($group);
        $em->persist($attribute);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $attribute, 1));
        $em->persist(new ObjectTypeAttributeGroup($type, $group, 1));
        $em->flush();
        $tenantContext->clear();

        $source = $this->makeProduct('SRC-RELGRP');

        $client = $this->authenticatedClient();
        $listResp = $client->request(
            'GET',
            '/api/objects/'.$source->getId()->toRfc4122().'/relations',
        );
        self::assertSame(200, $listResp->getStatusCode());

        /** @var list<array<string, mixed>> $relationAttributes */
        $relationAttributes = $listResp->toArray()['relationAttributes'];
        $entry = $this->groupForCode($relationAttributes, 'rel_via_group_attr');
        /** @var array{code: string, cardinality: ?string} $attr */
        $attr = $entry['attribute'];
        self::assertSame('rel_via_group_attr', $attr['code']);
        self::assertSame('many', $attr['cardinality']);
        self::assertSame([], $entry['relations']);
    }

    /**
     * @param list<array<string, mixed>> $groups
     *
     * @return array<string, mixed>
     */
    private function groupForCode(array $groups, string $code): array
    {
        foreach ($groups as $group) {
            /** @var array{code: string} $attribute */
            $attribute = $group['attribute'];
            if ($code === $attribute['code']) {
                return $group;
            }
        }
        self::fail(\sprintf('Group for attribute code "%s" not found in response.', $code));
    }

    private function makeProduct(string $sku): CatalogObject
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        // TenantContext is required by TenantAssignmentListener at persist time.
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $object = new CatalogObject($productType, $sku);
        $em->persist($object);
        $em->flush();

        return $object;
    }
}
