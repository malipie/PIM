<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * XMLF-P0-05 — XML as an ad-hoc export format (ADR-0023 §6.10). The sync
 * export endpoint accepts `format=xml` and serves `application/xml`; the
 * generic `<products><product>` serialization itself is unit-tested
 * (XmlWriterCore / GenericXmlWriter / RowSink) and smoke-tested end-to-end.
 */
final class XmlExportApiTest extends CatalogApiTestCase
{
    #[Test]
    public function syncXmlExportServesApplicationXml(): void
    {
        $this->seedRootProducts(2);

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/products/export', [
            'json' => [
                'entity_type' => 'product',
                'format' => 'xml',
                'target_scope' => 'all',
                'selected_columns' => ['sku', 'name'],
                'include_variants' => false,
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        self::assertStringContainsString('application/xml', $contentType);
    }

    #[Test]
    public function rejectsUnknownFormat(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/products/export', [
            'json' => [
                'format' => 'json',
                'target_scope' => 'all',
                'selected_columns' => ['sku'],
            ],
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    private function seedRootProducts(int $count): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
        for ($i = 1; $i <= $count; ++$i) {
            $repo->save(new CatalogObject($type, \sprintf('XMLF-%03d', $i)));
        }

        $this->em()->clear();
    }
}
