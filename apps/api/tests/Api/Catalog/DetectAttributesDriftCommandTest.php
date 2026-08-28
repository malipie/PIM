<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use const JSON_THROW_ON_ERROR;

/**
 * AUD-039 / G-01 — `pim:catalog:detect-attributes-drift` finds an
 * `attributes_indexed` key with no canonical `object_values` row (the exact
 * ACME-001/002/003 shape the audit probe found in dev: cache populated, zero
 * global values), fails with a non-zero exit so CI / cron catches it, and
 * `--reconcile` rewrites the cache from the canon so the next scan is clean.
 *
 * The orphaned key is planted with a raw `UPDATE` on `attributes_indexed`
 * (bypassing the rebuilder) to reproduce drift without an actual ObjectValue —
 * the same on-disk state the drift produces in production.
 */
final class DetectAttributesDriftCommandTest extends CatalogApiTestCase
{
    #[Test]
    public function detectsOrphanedCacheKeyThenReconcileClearsIt(): void
    {
        $client = $this->authenticatedClient();
        $productOt = $this->objectTypeIdFor(ObjectKind::Product);

        // A custom (non-system) attribute so its value lands in the GLOBAL cache
        // — `name`/`sku` are read-overlay system attributes that never enter
        // attributes_indexed, so they would not exercise the legit-key path.
        $legitAttrId = $this->createTextAttribute($client, 'legit_attr');
        $client->request('POST', '/api/object_types/'.$productOt.'/attributes/'.$legitAttrId);
        self::assertResponseStatusCodeSame(204);

        // A legitimate object: one real value → one canonical object_values row
        // + one matching cache entry. This entry must SURVIVE the reconcile.
        $response = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'DRIFT-1',
                'objectTypeId' => $productOt,
                'attributes' => ['legit_attr' => 'Legit Value'],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $objectId = $response->toArray()['id'];
        \assert(\is_string($objectId));

        // Plant an orphaned cache key ("ghost") with no object_values backing —
        // the canon ({name}) knows nothing about it. Take the real cache (keeps
        // the genuine `name` envelope), add the ghost, write it back verbatim as
        // a JSON object. Raw UPDATE so the sync listener does not rebuild it away;
        // a full-object write also sidesteps `jsonb_set` choking on the empty
        // `[]` Doctrine serialises for a value-less map.
        $conn = $this->connection();
        $current = $conn->fetchOne('SELECT attributes_indexed FROM objects WHERE id = :id', ['id' => $objectId]);
        \assert(\is_string($current));
        /** @var array<string, mixed> $cache */
        $cache = json_decode($current, true, 512, JSON_THROW_ON_ERROR);
        $cache['ghost'] = ['value' => 'orphan'];
        $conn->executeStatement(
            'UPDATE objects SET attributes_indexed = CAST(:cache AS jsonb) WHERE id = :id',
            ['cache' => json_encode($cache, JSON_THROW_ON_ERROR), 'id' => $objectId],
        );

        // Sanity: the orphan really is on disk now.
        $cacheBefore = $this->cacheKeys($conn, $objectId);
        self::assertContains('ghost', $cacheBefore, 'orphan must be planted before detect');
        self::assertContains('legit_attr', $cacheBefore, 'the legit key must coexist');

        // detect (no reconcile): non-zero exit, the object + orphaned key listed.
        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--tenant' => self::TENANT_CODE, '--kind' => 'product']);
        self::assertSame(Command::FAILURE, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('drifted=1', $display);
        self::assertStringContainsString('DRIFT-1', $display);
        self::assertStringContainsString('orphaned=ghost', $display);

        // detect did not mutate anything.
        self::assertContains('ghost', $this->cacheKeys($conn, $objectId), 'detect must be read-only');

        // detect --reconcile: success, orphan removed, legit key kept.
        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--tenant' => self::TENANT_CODE,
            '--kind' => 'product',
            '--reconcile' => true,
        ]);
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('reconciled=1', $tester->getDisplay());

        $cacheAfter = $this->cacheKeys($conn, $objectId);
        self::assertNotContains('ghost', $cacheAfter, 'reconcile must drop the orphaned key');
        self::assertContains('legit_attr', $cacheAfter, 'reconcile must keep the canonical value');

        // The canon was never touched: still exactly one object_values row.
        $valueCount = $conn->fetchOne(
            'SELECT COUNT(*) FROM object_values WHERE object_id = :id',
            ['id' => $objectId],
        );
        self::assertSame(
            1,
            (int) (\is_scalar($valueCount) ? $valueCount : 0),
            'reconcile must not delete or add object_values rows',
        );

        // Re-scan: drift is gone, clean exit.
        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--tenant' => self::TENANT_CODE, '--kind' => 'product']);
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('No drift', $tester->getDisplay());
    }

    /**
     * GOLIVE #2186 — select values written through the REAL path (API write →
     * sync listener → globalSlot → jsonb) must NOT drift: Postgres jsonb
     * reorders object keys (`provenance` sorts before `option_code`), so the
     * old strict key-order-sensitive comparison reported an eternal false
     * mismatch that no --reconcile could clear.
     */
    #[Test]
    public function selectValueWrittenThroughRealPathReportsNoDrift(): void
    {
        $this->seedSelectWithOptions('drift_color', ['red', 'green']);
        $this->seedSelectWithOptions('drift_tags', ['new', 'sale'], AttributeType::Multiselect);
        $client = $this->authenticatedClient();
        $productOt = $this->objectTypeIdFor(ObjectKind::Product);

        $response = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'DRIFT-SEL-1',
                'objectTypeId' => $productOt,
                'attributes' => [
                    'drift_color' => ['option_code' => 'green'],
                    'drift_tags' => ['option_codes' => ['new', 'sale']],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--tenant' => self::TENANT_CODE, '--kind' => 'product']);
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('No drift', $tester->getDisplay());
    }

    /**
     * @param list<string> $optionCodes
     */
    private function seedSelectWithOptions(
        string $code,
        array $optionCodes,
        AttributeType $type = AttributeType::Select,
    ): void {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $attribute = new Attribute($code, ['pl' => $code, 'en' => $code], $type);
        $em->persist($attribute);
        $position = 0;
        foreach ($optionCodes as $optionCode) {
            $em->persist(new AttributeOption(
                attribute: $attribute,
                code: $optionCode,
                label: ['pl' => ucfirst($optionCode), 'en' => ucfirst($optionCode)],
                position: $position++,
            ));
        }
        $em->persist(new ObjectTypeAttribute($product, $attribute, false, 1));
        $em->flush();
    }

    private function createTextAttribute(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $code,
    ): string {
        $response = $client->request('POST', '/api/attributes', [
            'headers' => ['content-type' => 'application/ld+json', 'accept' => 'application/ld+json'],
            'body' => json_encode([
                'code' => $code,
                'type' => 'text',
                'label' => ['pl' => $code, 'en' => $code],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $response->toArray()['id'];
        \assert(\is_string($id));

        return $id;
    }

    /**
     * @return list<string>
     */
    private function cacheKeys(Connection $conn, string $objectId): array
    {
        $raw = $conn->fetchOne(
            'SELECT attributes_indexed FROM objects WHERE id = :id',
            ['id' => $objectId],
        );
        \assert(\is_string($raw));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return array_keys($decoded);
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('pim:catalog:detect-attributes-drift'));
    }
}
