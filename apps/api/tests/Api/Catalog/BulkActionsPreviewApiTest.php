<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\ObjectKind;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * #2735 — the preview endpoint used to extrapolate its sample: with 10 000
 * targets and a 5-object sample it returned `success_count: 9997`, claiming
 * thousands of unverified objects would succeed. The response now reports
 * counts scoped to the sample (`sample_size` / `sample_error_count` /
 * `sample_skipped_count`) next to the raw `target_count` and never a derived
 * success figure.
 */
final class BulkActionsPreviewApiTest extends CatalogApiTestCase
{
    #[Test]
    public function previewReportsSampleScopedCountsWithoutExtrapolation(): void
    {
        $client = $this->authenticatedClient();
        $productOt = $this->objectTypeIdFor(ObjectKind::Product);

        $ids = [];
        foreach (range(1, 7) as $i) {
            $created = $client->request('POST', '/api/products', [
                'headers' => ['content-type' => 'application/ld+json'],
                'body' => json_encode([
                    'code' => \sprintf('PREV-%d', $i),
                    'objectTypeId' => $productOt,
                    'attributes' => [],
                ], JSON_THROW_ON_ERROR),
            ]);
            self::assertResponseStatusCodeSame(201);
            $id = $created->toArray()['id'];
            \assert(\is_string($id));
            $ids[] = $id;
        }

        // One target id points at a nonexistent object — a sample error.
        $targets = [...\array_slice($ids, 0, 4), '00000000-0000-0000-0000-000000000000', ...\array_slice($ids, 4)];

        $response = $client->request('POST', '/api/products/bulk-actions/preview', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'action' => 'set_attribute',
                'target_ids' => $targets,
                'payload' => ['attr' => 'name', 'value' => 'Nowa nazwa'],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();

        self::assertSame(8, $body['target_count']);
        self::assertSame(5, $body['sample_size'], 'the preview inspects at most 5 objects');
        self::assertSame(1, $body['sample_error_count'], 'the ghost id in the sample is one error');
        self::assertSame(0, $body['sample_skipped_count']);
        self::assertArrayNotHasKey('success_count', $body, 'no extrapolated success figure (#2735)');
        self::assertIsArray($body['sample']);
        self::assertCount(4, $body['sample'], '4 real objects in the sample produce diffs');
    }

    #[Test]
    public function previewRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/products/bulk-actions/preview', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'action' => 'set_attribute',
                'target_ids' => ['00000000-0000-0000-0000-000000000000'],
                'payload' => ['attribute' => 'name', 'value' => 'x'],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
