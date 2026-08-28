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

/**
 * ADR-014 / MOD-08 (#900) — advanced relations carry per-link metadata
 * validated against the attribute's `validation_rules.advanced_fields`
 * schema.
 */
final class ObjectRelationsAdvancedMetadataApiTest extends CatalogApiTestCase
{
    #[Test]
    public function putAcceptsMetadataMatchingAdvancedFieldsSchema(): void
    {
        $client = $this->authenticatedClient();
        $source = $this->makeProduct('SRC-ADV-1');
        $target = $this->makeProduct('TGT-ADV-1');
        $attr = $this->createAdvancedRelationAttribute('priority_accessories');

        $resp = $client->request('PUT', '/api/objects/'.$source->getId()->toRfc4122().'/relations/'.$attr->getCode(), [
            'json' => [
                'targets' => [
                    [
                        'id' => $target->getId()->toRfc4122(),
                        'metadata' => [
                            'priority' => 5,
                            'recommended' => true,
                        ],
                    ],
                ],
            ],
        ]);
        self::assertSame(204, $resp->getStatusCode(), $resp->getContent(false));

        $listResp = $client->request('GET', '/api/objects/'.$source->getId()->toRfc4122().'/relations');
        $body = $listResp->toArray();
        /** @var list<array{attribute: array{code: string}, relations: list<array{metadata: array<string, mixed>}>}> $groups */
        $groups = $body['relationAttributes'];
        $group = null;
        foreach ($groups as $g) {
            if ($attr->getCode() === $g['attribute']['code']) {
                $group = $g;
                break;
            }
        }
        self::assertNotNull($group);
        self::assertCount(1, $group['relations']);
        self::assertSame(['priority' => 5, 'recommended' => true], $group['relations'][0]['metadata']);
    }

    #[Test]
    public function putRejectsMetadataMissingRequiredField(): void
    {
        $client = $this->authenticatedClient();
        $source = $this->makeProduct('SRC-ADV-2');
        $target = $this->makeProduct('TGT-ADV-2');
        $attr = $this->createAdvancedRelationAttribute('required_meta');

        $client->request('PUT', '/api/objects/'.$source->getId()->toRfc4122().'/relations/'.$attr->getCode(), [
            'json' => [
                'targets' => [
                    [
                        'id' => $target->getId()->toRfc4122(),
                        'metadata' => ['recommended' => true],
                    ],
                ],
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function putRejectsMetadataWithWrongFieldType(): void
    {
        $client = $this->authenticatedClient();
        $source = $this->makeProduct('SRC-ADV-3');
        $target = $this->makeProduct('TGT-ADV-3');
        $attr = $this->createAdvancedRelationAttribute('type_meta');

        $client->request('PUT', '/api/objects/'.$source->getId()->toRfc4122().'/relations/'.$attr->getCode(), [
            'json' => [
                'targets' => [
                    [
                        'id' => $target->getId()->toRfc4122(),
                        'metadata' => [
                            'priority' => 'high',          // expected number, got string
                            'recommended' => true,
                        ],
                    ],
                ],
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function putRejectsMetadataOnNonAdvancedAttribute(): void
    {
        $client = $this->authenticatedClient();
        $source = $this->makeProduct('SRC-NONADV');
        $target = $this->makeProduct('TGT-NONADV');
        $attr = $this->createRelationAttribute('plain_meta', advanced: false);

        $client->request('PUT', '/api/objects/'.$source->getId()->toRfc4122().'/relations/'.$attr->getCode(), [
            'json' => [
                'targets' => [
                    [
                        'id' => $target->getId()->toRfc4122(),
                        'metadata' => ['priority' => 1],
                    ],
                ],
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    private function createAdvancedRelationAttribute(string $code): Attribute
    {
        return $this->createRelationAttribute($code, advanced: true);
    }

    private function createRelationAttribute(string $code, bool $advanced): Attribute
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $attribute = new Attribute($code, ['en' => $code], AttributeType::Relation);
        $attribute->setRelationCardinality(RelationCardinality::Many);
        $attribute->setRelationTargetObjectTypeIds([$productType->getId()->toRfc4122()]);
        $attribute->setRelationAdvanced($advanced);
        if ($advanced) {
            $attribute->updateValidationRules([
                'advanced_fields' => [
                    ['code' => 'priority', 'type' => 'number', 'label' => ['en' => 'Priority'], 'required' => true],
                    ['code' => 'recommended', 'type' => 'boolean', 'label' => ['en' => 'Recommended'], 'required' => false],
                ],
            ]);
        }
        $em->persist($attribute);
        $em->flush();

        // Wire to Product ObjectType so the relations list endpoint sees it.
        $em->persist(new ObjectTypeAttribute($productType, $attribute));
        $em->flush();

        return $attribute;
    }

    private function makeProduct(string $sku): CatalogObject
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
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
