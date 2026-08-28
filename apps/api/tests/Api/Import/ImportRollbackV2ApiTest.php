<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use const JSON_THROW_ON_ERROR;

/**
 * IMP2-2.4 — rollback v2: restores values an upsert OVERWROTE on pre-existing
 * objects (not just deletes created ones), leaves values edited by hand after
 * the import (reported as skips), and the preview reports the buckets up front.
 */
final class ImportRollbackV2ApiTest extends CatalogApiTestCase
{
    #[Test]
    public function rollbackRestoresOverwrittenValuesAndDeletesCreated(): void
    {
        $this->seedSkuName();

        // Import #1 seeds RBK-1..3 with the "old" names.
        $this->import("sku;name\nRBK-1;Old1\nRBK-2;Old2\nRBK-3;Old3\n");
        // Import #2 overwrites those names and creates RBK-4..5.
        $sessionId = $this->import("sku;name\nRBK-1;New1\nRBK-2;New2\nRBK-3;New3\nRBK-4;New4\nRBK-5;New5\n");

        self::assertSame('New1', $this->nameOf('RBK-1'), 'precondition: import #2 overwrote the name');
        self::assertSame(5, $this->countObjects('RBK-%'));

        // Rollback session #2.
        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback', $sessionId));
        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        self::assertSame('rolled_back', $body['status']);
        self::assertSame(2, $body['deleted_objects'], 'RBK-4 + RBK-5 deleted');
        // Each updated row rewrote BOTH mapped values (sku + name) on the
        // existing object → 3 objects × 2 = 6 overwrites reversed.
        self::assertSame(6, $body['restored_values']);
        self::assertSame(0, $body['skipped_manual_edits']);

        // Overwritten names are back; created objects are gone.
        self::assertSame('Old1', $this->nameOf('RBK-1'));
        self::assertSame('Old3', $this->nameOf('RBK-3'));
        self::assertSame(3, $this->countObjects('RBK-%'));
    }

    #[Test]
    public function manualEditAfterImportIsNotRevertedAndIsReported(): void
    {
        $this->seedSkuName();
        $this->import("sku;name\nMAN-1;Old1\nMAN-2;Old2\n");
        $sessionId = $this->import("sku;name\nMAN-1;New1\nMAN-2;New2\n");

        // Simulate a manual UI edit after the import: provenance flips to manual.
        $this->em()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE object_values SET provenance = 'manual'
                WHERE attribute_id = (SELECT id FROM attributes WHERE code = 'name' LIMIT 1)
                  AND object_id = (SELECT id FROM objects WHERE code = 'MAN-2' LIMIT 1)
                SQL,
        );

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback', $sessionId));
        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        // MAN-1 sku + MAN-1 name + MAN-2 sku restored; MAN-2 name skipped (manual).
        self::assertSame(3, $body['restored_values']);
        self::assertSame(1, $body['skipped_manual_edits'], 'MAN-2 name left alone');
        self::assertSame('Old1', $this->nameOf('MAN-1'), 'import-owned value reverted');
        self::assertSame('New2', $this->nameOf('MAN-2'), 'hand-edited value preserved');
    }

    #[Test]
    public function rollbackLeavesValuesALaterImportOverwroteInstamentOfClobberingThem(): void
    {
        $this->seedSkuName();
        // Three imports stack on the same SKU. Import #2's undo-log captures the
        // before-state, but import #3 then overwrites the same cell. Rolling back
        // #2 must NOT revert #3's write (both carry provenance `import`).
        $this->import("sku;name\nICL-1;V1\n");
        $session2 = $this->import("sku;name\nICL-1;V2\n");
        $this->import("sku;name\nICL-1;V3\n");

        self::assertSame('V3', $this->nameOf('ICL-1'), 'precondition: import #3 owns the value');

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback', $session2));
        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        self::assertSame('rolled_back', $body['status']);
        self::assertSame(0, $body['restored_values'], 'nothing reverted: a later import owns every cell');
        self::assertSame(2, $body['skipped_superseded'], 'sku + name both superseded by import #3');
        self::assertSame('V3', $this->nameOf('ICL-1'), 'import #3 preserved — no clobber');
    }

    #[Test]
    public function rollbackRebuildsIndexedCachesForRestoredObjects(): void
    {
        // IMP2-2.4 — after the value undo-log is replayed, the rollback rebuilds
        // attributes_indexed + completeness for every restored object (so list
        // views / Meili / completeness badges reflect the pre-import state, not
        // the stale post-import cache). `name` is a required code, so the
        // completeness reading is meaningful.
        $this->seedSkuName();
        $this->requireNameForCompleteness();

        // Import #1 seeds IDX-1 with the "old" name; #2 overwrites it.
        $this->import("sku;name\nIDX-1;Old1\n");
        $sessionId = $this->import("sku;name\nIDX-1;New1\n");

        // Precondition on the canonical value only (synchronous on the write
        // path). The denormalised attributes_indexed / completeness caches are
        // rebuilt via an async-dispatched message on the import path, so their
        // post-import state is not guaranteed inline — we assert them only after
        // the rollback's SYNCHRONOUS rebuild below, which is exactly what this
        // test pins.
        self::assertSame('New1', $this->nameOf('IDX-1'), 'precondition: canonical value reflects the overwrite');

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback', $sessionId));
        self::assertResponseIsSuccessful();
        $body = $this->decode($client);
        self::assertSame('rolled_back', $body['status']);

        // The canonical ObjectValue is back to Old1 AND the rebuilt cache mirrors
        // it — proof the rebuilder ran for the restored object, not just the
        // ObjectValue replay.
        self::assertSame('Old1', $this->nameOf('IDX-1'), 'canonical value restored');
        self::assertSame('Old1', $this->indexedNameOf('IDX-1'), 'attributes_indexed rebuilt from restored value');
        self::assertSame(100, $this->completenessGlobalOf('IDX-1'), 'completeness recomputed after rollback');
    }

    #[Test]
    public function previewReportsBucketsWithoutMutating(): void
    {
        $this->seedSkuName();
        $this->import("sku;name\nPRV-1;Old1\nPRV-2;Old2\n");
        $sessionId = $this->import("sku;name\nPRV-1;New1\nPRV-2;New2\nPRV-3;New3\n");

        $client = $this->authenticatedClient();
        $client->request('GET', \sprintf('/api/import-sessions/%s/rollback-preview', $sessionId));
        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        self::assertSame(1, $body['created_to_delete'], 'PRV-3 is new');
        // PRV-1 + PRV-2, each with sku + name overwritten → 4.
        self::assertSame(4, $body['values_to_restore']);
        self::assertTrue($body['rollbackable']);
        // Preview must not mutate.
        self::assertSame('New1', $this->nameOf('PRV-1'));
        self::assertSame(3, $this->countObjects('PRV-%'));
    }

    private function import(string $csv): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-rbk-').'.csv';
        file_put_contents($path, $csv);

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => ['file' => new UploadedFile($path, 'rbk.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseIsSuccessful();

            $id = $this->decode($client)['id'] ?? null;

            return \is_scalar($id) ? (string) $id : '';
        } finally {
            @unlink($path);
        }
    }

    private function nameOf(string $code): ?string
    {
        $value = $this->em()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT ov.value->>'value'
                FROM object_values ov
                JOIN objects o ON o.id = ov.object_id
                JOIN attributes a ON a.id = ov.attribute_id
                WHERE o.code = :code AND a.code = 'name'
                LIMIT 1
                SQL,
            ['code' => $code],
        );

        return \is_scalar($value) && false !== $value ? (string) $value : null;
    }

    private function indexedNameOf(string $code): ?string
    {
        $value = $this->em()->getConnection()->fetchOne(
            "SELECT attributes_indexed->'name'->>'value' FROM objects WHERE code = :code LIMIT 1",
            ['code' => $code],
        );

        return \is_scalar($value) && false !== $value ? (string) $value : null;
    }

    private function completenessGlobalOf(string $code): ?int
    {
        $value = $this->em()->getConnection()->fetchOne(
            "SELECT completeness->>'global' FROM objects WHERE code = :code LIMIT 1",
            ['code' => $code],
        );

        return \is_scalar($value) && false !== $value && '' !== $value ? (int) $value : null;
    }

    private function requireNameForCompleteness(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);
        $product->updateCompletenessRules(['required' => ['name']]);
        $em->flush();
    }

    private function countObjects(string $like): int
    {
        $count = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM objects WHERE code LIKE :like',
            ['like' => $like],
        );

        return (int) (\is_scalar($count) ? $count : 0);
    }

    /**
     * @return array<mixed>
     */
    private function decode(\ApiPlatform\Symfony\Bundle\Test\Client $client): array
    {
        $decoded = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function seedSkuName(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($sku);
        $em->persist($name);
        $em->persist(new ObjectTypeAttribute($product, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($product, $name, false, 2));
        $em->flush();
    }
}
