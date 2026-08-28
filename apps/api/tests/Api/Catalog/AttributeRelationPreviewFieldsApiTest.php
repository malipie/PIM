<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * MODR-08 (#930) follow-up — `GET /api/attributes/{id}/relation_preview_fields`
 * exposes the JSONB list omitted from the standard ApiPlatform JSON-LD
 * response for the Attribute entity.
 */
final class AttributeRelationPreviewFieldsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function getReturnsEmptyListByDefault(): void
    {
        $client = $this->authenticatedClient();
        $attr = $this->seedAttribute('mod_attr_a');

        $body = $client->request(
            'GET',
            '/api/attributes/'.$attr->getId()->toRfc4122().'/relation_preview_fields',
        )->toArray();

        self::assertSame($attr->getId()->toRfc4122(), $body['attributeId']);
        self::assertSame([], $body['relationPreviewFields']);
    }

    #[Test]
    public function patchPlusGetRoundTripsTheConfiguredList(): void
    {
        $client = $this->authenticatedClient();
        $attr = $this->seedAttribute('mod_attr_b');

        $client->request('PATCH', '/api/attributes/'.$attr->getId()->toRfc4122(), [
            'json' => ['relationPreviewFields' => ['sku', 'price']],
            'headers' => ['content-type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();

        $body = $client->request(
            'GET',
            '/api/attributes/'.$attr->getId()->toRfc4122().'/relation_preview_fields',
        )->toArray();
        self::assertSame(['sku', 'price'], $body['relationPreviewFields']);
    }

    #[Test]
    public function getReturns404ForUnknownAttribute(): void
    {
        $client = $this->authenticatedClient();
        $client->request(
            'GET',
            '/api/attributes/01234567-1234-7000-8000-000000000000/relation_preview_fields',
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function seedAttribute(string $code): Attribute
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $attr = new Attribute($code, ['pl' => 'Test', 'en' => 'Test'], AttributeType::Relation);
        $em = $this->em();
        $em->persist($attr);
        $em->flush();

        $tenantContext->clear();

        return $attr;
    }
}
