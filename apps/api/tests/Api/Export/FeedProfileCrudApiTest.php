<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use App\Catalog\Domain\ObjectKind;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * XMLF-P1-03 — FeedProfile CRUD (POST/GET/PATCH/DELETE /api/feeds + pause/resume).
 */
final class FeedProfileCrudApiTest extends CatalogApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'template_kind' => 'custom',
            'code' => 'b2b_stalko',
            'name' => 'Feed B2B — Stalko',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'descriptor' => [
                'root' => ['element' => 'products'],
                'item' => [
                    'element' => 'product',
                    'slots' => [['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text']],
                ],
            ],
            'field_mappings' => [['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']]],
            'locale' => 'pl',
            'currency' => 'PLN',
        ];
    }

    #[Test]
    public function createsListsGetsAndDeletesAFeed(): void
    {
        $client = $this->authenticatedClient();

        $created = $client->request('POST', '/api/feeds', ['json' => $this->payload()]);
        self::assertSame(201, $created->getStatusCode());
        $body = $created->toArray(false);
        self::assertSame('b2b_stalko', $body['code']);
        self::assertSame('custom', $body['template_kind']);
        self::assertSame('skip_invalid', $body['validation_policy']);
        self::assertArrayNotHasKey('token_hash', $body);
        $id = $body['id'];
        self::assertIsString($id);

        $list = $client->request('GET', '/api/feeds');
        self::assertSame(200, $list->getStatusCode());
        self::assertNotEmpty($list->toArray(false)['items']);

        self::assertSame(200, $client->request('GET', '/api/feeds/'.$id)->getStatusCode());

        $paused = $client->request('POST', '/api/feeds/'.$id.'/pause');
        self::assertSame('paused', $paused->toArray(false)['status']);

        self::assertSame(204, $client->request('DELETE', '/api/feeds/'.$id)->getStatusCode());
        self::assertSame(404, $client->request('GET', '/api/feeds/'.$id)->getStatusCode());
    }

    #[Test]
    public function rejectsAnInvalidDescriptor(): void
    {
        $client = $this->authenticatedClient();
        $payload = $this->payload();
        $payload['code'] = 'bad_feed';
        $payload['descriptor'] = ['root' => ['element' => 'products']]; // no item

        $response = $client->request('POST', '/api/feeds', ['json' => $payload]);
        self::assertSame(400, $response->getStatusCode());
    }
}
