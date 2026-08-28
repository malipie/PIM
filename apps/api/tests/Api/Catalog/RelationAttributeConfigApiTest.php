<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\RelationCardinality;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR-014 / MOD-05 (#897) — POST/PATCH `/api/attributes` with the new
 * `relation_*` config columns.
 */
final class RelationAttributeConfigApiTest extends CatalogApiTestCase
{
    #[Test]
    public function postCreatesRelationAttributeWithFullConfig(): void
    {
        $client = $this->authenticatedClient();
        $productId = $this->productObjectTypeId();

        $response = $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'upsell_link',
                'label' => ['en' => 'Up-sell', 'pl' => 'Up-sell'],
                'type' => 'relation',
                'relationTargetObjectTypeIds' => [$productId],
                'relationCardinality' => 'many',
                'relationAdvanced' => true,
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $body = $response->toArray();
        self::assertSame('relation', $body['type']);
        self::assertSame([$productId], $body['relationTargetObjectTypeIds']);
        self::assertSame('many', $body['relationCardinality']);
        self::assertTrue($body['relationAdvanced']);
    }

    #[Test]
    public function postRejectsRelationAttributeWithoutCardinality(): void
    {
        $client = $this->authenticatedClient();
        $productId = $this->productObjectTypeId();

        $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'no_card',
                'label' => ['en' => 'Bad'],
                'type' => 'relation',
                'relationTargetObjectTypeIds' => [$productId],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function postRejectsRelationAttributeWithUnknownTargetObjectType(): void
    {
        $client = $this->authenticatedClient();

        $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'bad_target',
                'label' => ['en' => 'Bad'],
                'type' => 'relation',
                'relationTargetObjectTypeIds' => ['01234567-1234-7000-8000-000000000000'],
                'relationCardinality' => 'one',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function postRejectsRelationConfigOnTextAttribute(): void
    {
        $client = $this->authenticatedClient();
        $productId = $this->productObjectTypeId();

        $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'mixed_up',
                'label' => ['en' => 'Bad'],
                'type' => 'text',
                'relationTargetObjectTypeIds' => [$productId],
                'relationCardinality' => 'one',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function patchUpdatesRelationConfigOnExistingRelationAttribute(): void
    {
        $client = $this->authenticatedClient();
        $productId = $this->productObjectTypeId();

        $createResp = $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'config_evolves',
                'label' => ['en' => 'Evolves'],
                'type' => 'relation',
                'relationTargetObjectTypeIds' => [$productId],
                'relationCardinality' => 'one',
                'relationAdvanced' => false,
            ],
        ]);
        self::assertSame(201, $createResp->getStatusCode());
        $rawId = $createResp->toArray()['id'];
        \assert(\is_string($rawId));
        $createdId = $rawId;

        $patchResp = $client->request('PATCH', '/api/attributes/'.$createdId, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'relationCardinality' => 'many',
                'relationAdvanced' => true,
            ],
        ]);
        self::assertSame(200, $patchResp->getStatusCode(), $patchResp->getContent(false));
        $body = $patchResp->toArray();
        self::assertSame('many', $body['relationCardinality']);
        self::assertTrue($body['relationAdvanced']);

        // ORM round-trip — values land in the DB columns from MOD-01.
        $reloaded = $this->attributeRepository()->findById(\Symfony\Component\Uid\Uuid::fromString($createdId));
        self::assertNotNull($reloaded);
        self::assertSame(AttributeType::Relation, $reloaded->getType());
        self::assertSame(RelationCardinality::Many, $reloaded->getRelationCardinality());
        self::assertTrue($reloaded->isRelationAdvanced());
    }

    private function productObjectTypeId(): string
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $product);

        return $product->getId()->toRfc4122();
    }

    private function attributeRepository(): AttributeRepositoryInterface
    {
        return self::getContainer()->get(AttributeRepositoryInterface::class);
    }
}
