<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Backup\Domain\Entity\Backup;
use App\Backup\Domain\Enum\BackupStatus;
use App\Backup\Domain\Enum\BackupTriggerAction;
use App\Backup\Domain\Repository\BackupRepositoryInterface;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\RelationCardinality;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectRelationRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * IMP-04 (#445) — wizard Step 4 confirm round-trips through the
 * persisting endpoint. Sync small import (<50 rows) executes inline
 * and surfaces `status=success` plus the matching CatalogObject rows
 * stamped with the session id.
 */
final class StartImportApiTest extends CatalogApiTestCase
{
    #[Test]
    public function smallImportRunsInlineAndPersistsProducts(): void
    {
        $this->seedAttributes();

        $csvPath = $this->writeSmallCsv();

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'files' => [
                        'file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true),
                    ],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertNotNull($response);
            $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);

            self::assertSame(
                'success',
                $body['status'],
                \sprintf(
                    'Expected success but body=%s',
                    json_encode($body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                ),
            );
            self::assertSame(5, $body['success_count']);
            self::assertSame(0, $body['error_count']);
            self::assertNotNull($body['rollback_until'], 'Successful imports open the 24h rollback window.');

            // Imported products are stamped with the session id and
            // discoverable via the catalog repository.
            $em = $this->em();
            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);

            $product = self::getContainer()
                ->get(ObjectTypeRepositoryInterface::class)
                ->findBuiltInByKind(ObjectKind::Product, $tenant);
            \assert($product instanceof ObjectType);

            $imported = $em->createQueryBuilder()
                ->select('o')
                ->from(CatalogObject::class, 'o')
                ->where('o.objectType = :type')
                ->andWhere('o.importSessionId = :sessionId')
                ->setParameter('type', $product)
                ->setParameter('sessionId', $body['id'])
                ->getQuery()
                ->getResult();

            self::assertCount(5, $imported);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function importAssignsRowsToTheirCategoriesAndWarnsOnMissingOnes(): void
    {
        $this->seedAttributes();
        $this->seedCategories(['pneumatyka', 'elektronika']);

        $csvPath = $this->writeCategoryCsv();

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'kategoria' => '__category__',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'files' => [
                        'file' => new UploadedFile($csvPath, 'category-import.csv', 'text/csv', null, true),
                    ],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertNotNull($response);
            $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);

            self::assertSame('success', $body['status'], 'Rows with missing categories still import — only the assignment is dropped.');
            self::assertSame(3, $body['success_count']);
            self::assertSame(0, $body['error_count']);

            $em = $this->em();
            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);

            $catalogObjects = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
            $categories = self::getContainer()->get(ObjectCategoryRepositoryInterface::class);

            $sku1 = $catalogObjects->findByCode('CAT-1', ObjectKind::Product, $tenant);
            $sku2 = $catalogObjects->findByCode('CAT-2', ObjectKind::Product, $tenant);
            $sku3 = $catalogObjects->findByCode('CAT-3', ObjectKind::Product, $tenant);
            self::assertNotNull($sku1);
            self::assertNotNull($sku2);
            self::assertNotNull($sku3, 'CAT-3 has an unknown category code but still imports the product.');

            self::assertCount(1, $categories->findByProduct($sku1));
            $primary1 = $categories->findPrimary($sku1);
            self::assertNotNull($primary1);
            self::assertSame('pneumatyka', $primary1->getCategory()->getCode());

            self::assertCount(1, $categories->findByProduct($sku2));
            $primary2 = $categories->findPrimary($sku2);
            self::assertNotNull($primary2);
            self::assertSame('elektronika', $primary2->getCategory()->getCode());

            self::assertCount(0, $categories->findByProduct($sku3), 'Unknown category yields no assignment for CAT-3.');
            self::assertNull($categories->findPrimary($sku3));
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function importPipeSplitsCategoriesWithPrimaryPositionAndPerCodeWarning(): void
    {
        // IMP2-1.7 (AC 1,2) — `cat-a|cat-b|cat-c` → 3 assignments (first
        // primary, position by order); a code missing mid-list is dropped
        // with a per-code warning, the row still imports with the rest.
        $this->seedAttributes();
        $this->seedCategories(['cat_a', 'cat_b', 'cat_c']);

        $csvPath = $this->writeCsv(
            "sku;name;kategoria\nMULTI-1;Trzy;cat_a|cat_b|cat_c\nMULTI-2;Luka;cat_a|GHOST|cat_c\n",
            'pim-multicat-',
        );

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'kategoria' => '__category__',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'multi.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('success', $body['status']);
            self::assertSame(2, $body['success_count']);
            self::assertSame(0, $body['error_count'], 'a missing category is a warning, not a row error');

            $em = $this->em();
            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $catalogObjects = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
            $categories = self::getContainer()->get(ObjectCategoryRepositoryInterface::class);

            $m1 = $catalogObjects->findByCode('MULTI-1', ObjectKind::Product, $tenant);
            \assert(null !== $m1);
            $a1 = $categories->findByProduct($m1);
            self::assertCount(3, $a1);
            self::assertSame(['cat_a', 'cat_b', 'cat_c'], array_map(static fn ($a): string => $a->getCategory()->getCode(), $a1));
            self::assertSame([0, 1, 2], array_map(static fn ($a): int => $a->getPosition(), $a1));
            self::assertTrue($a1[0]->isPrimary());
            self::assertFalse($a1[1]->isPrimary());
            self::assertFalse($a1[2]->isPrimary());

            $m2 = $catalogObjects->findByCode('MULTI-2', ObjectKind::Product, $tenant);
            \assert(null !== $m2);
            $a2 = $categories->findByProduct($m2);
            self::assertCount(2, $a2, 'GHOST dropped, the row keeps cat_a + cat_c');
            self::assertSame(['cat_a', 'cat_c'], array_map(static fn ($a): string => $a->getCategory()->getCode(), $a2));

            $ghostWarnings = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE column_value = 'GHOST'",
            );
            self::assertSame(1, (int) (\is_scalar($ghostWarnings) ? $ghostWarnings : 0), 'per-code CategoryNotFound warning for GHOST');
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function appendModeAddsNewCategoriesWithoutDuplicatingExisting(): void
    {
        // IMP2-1.7 (#1470, D2 append policy) — `__category_append__` ADDS the
        // cell's categories to the product's existing assignments instead of
        // replacing them, and never duplicates a code already assigned. A
        // product seeded in `cat_a` re-imported with `cat_a|cat_b` ends up with
        // [cat_a, cat_b]: cat_a stays put (primary, position 0), cat_b is
        // appended at the next position. Logic: ImportRunHandler::appendCategories.
        $this->seedAttributes();
        $this->seedCategories(['cat_a', 'cat_b']);

        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $catalogObjects = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        // Seed an existing product already assigned to cat_a (primary).
        $product = new CatalogObject($productOt, 'APP-1');
        $em->persist($product);
        $catA = $catalogObjects->findByCode('cat_a', ObjectKind::Category, $tenant);
        \assert(null !== $catA);
        $em->persist(new \App\Catalog\Domain\Entity\ObjectCategory($product, $catA, true, 0));
        $em->flush();

        $csvPath = $this->writeCsv("sku;name;kategoria\nAPP-1;Nazwa;cat_a|cat_b\n", 'pim-catappend-');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'kategoria' => '__category_append__',
                        ], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'append.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame(0, $body['error_count']);

            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $catalogObjects = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
            $categories = self::getContainer()->get(ObjectCategoryRepositoryInterface::class);

            $reloaded = $catalogObjects->findByCode('APP-1', ObjectKind::Product, $tenant);
            \assert(null !== $reloaded);
            $assignments = $categories->findByProduct($reloaded);

            // cat_a is NOT duplicated — exactly [cat_a, cat_b], in append order.
            self::assertCount(2, $assignments, 'append adds cat_b without re-adding cat_a');
            self::assertSame(
                ['cat_a', 'cat_b'],
                array_map(static fn ($a): string => $a->getCategory()->getCode(), $assignments),
            );
            // cat_a keeps its original primary slot; cat_b is appended non-primary.
            self::assertTrue($assignments[0]->isPrimary());
            self::assertFalse($assignments[1]->isPrimary());
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function updateWithEmptyCategoryCellLeavesAssignmentsUntouched(): void
    {
        // IMP2-1.7 (#1470) / ADR-0019 D2 — an EMPTY category cell on an update
        // means "do not touch": the product's existing category assignments are
        // left exactly as they were, never wiped. A product seeded in two
        // categories re-imported with a blank `kategoria` cell keeps both.
        // Logic: ImportRunHandler::extractCategoryCodes drops empty cells, so no
        // pending category op is collected for the row.
        $this->seedAttributes();
        $this->seedCategories(['cat_a', 'cat_b']);

        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $catalogObjects = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        // Seed an existing product with TWO category assignments.
        $product = new CatalogObject($productOt, 'KEEP-1');
        $em->persist($product);
        $catA = $catalogObjects->findByCode('cat_a', ObjectKind::Category, $tenant);
        $catB = $catalogObjects->findByCode('cat_b', ObjectKind::Category, $tenant);
        \assert(null !== $catA);
        \assert(null !== $catB);
        $em->persist(new \App\Catalog\Domain\Entity\ObjectCategory($product, $catA, true, 0));
        $em->persist(new \App\Catalog\Domain\Entity\ObjectCategory($product, $catB, false, 1));
        $em->flush();

        // The category column is mapped but the cell is blank for this row.
        $csvPath = $this->writeCsv("sku;name;kategoria\nKEEP-1;Nowa nazwa;\n", 'pim-catkeep-');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'kategoria' => '__category__',
                        ], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'keep.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame(0, $body['error_count']);

            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $catalogObjects = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
            $categories = self::getContainer()->get(ObjectCategoryRepositoryInterface::class);

            $reloaded = $catalogObjects->findByCode('KEEP-1', ObjectKind::Product, $tenant);
            \assert(null !== $reloaded);
            $assignments = $categories->findByProduct($reloaded);

            // The empty cell touched nothing — both original assignments survive.
            self::assertCount(2, $assignments, 'empty category cell must not wipe existing assignments');
            self::assertSame(
                ['cat_a', 'cat_b'],
                array_map(static fn ($a): string => $a->getCategory()->getCode(), $assignments),
            );
            $primary = $categories->findPrimary($reloaded);
            self::assertNotNull($primary, 'the original primary is still intact');
            self::assertSame('cat_a', $primary->getCategory()->getCode());
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function importSetsStatusAndEnabledFromColumnsAndBlocksBadStatus(): void
    {
        // IMP2-1.7 (AC 4,5) — status/enabled columns set the object fields;
        // an out-of-enum status blocks the row (InvalidValue).
        $this->seedAttributes();

        $csvPath = $this->writeCsv(
            "sku;name;status;enabled\nST-1;Opublikowany;published;false\nST-2;Zly;bogus;true\n",
            'pim-status-',
        );

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'status' => '__status__',
                            'enabled' => '__enabled__',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'status.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame(1, $body['success_count']);
            self::assertSame(1, $body['error_count'], 'bogus status blocks the row');

            $em = $this->em();
            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $catalogObjects = self::getContainer()->get(CatalogObjectRepositoryInterface::class);

            $st1 = $catalogObjects->findByCode('ST-1', ObjectKind::Product, $tenant);
            \assert(null !== $st1);
            self::assertSame('published', $st1->getStatus());
            self::assertFalse($st1->isEnabled());

            self::assertNull(
                $catalogObjects->findByCode('ST-2', ObjectKind::Product, $tenant),
                'the row with a bogus status is rejected, not created',
            );
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function importLinksVariantsToParentRegardlessOfRowOrder(): void
    {
        // IMP2-1.8 (AC) — two-pass parent linking: a variant row may appear
        // BEFORE its master (VAR-2 → MST-2 which is a later row). A missing
        // parent → row still imports, session partial, warning logged.
        $this->seedAttributes();

        $csvPath = $this->writeCsv(
            "sku;name;parent_sku\n"
            ."MST-1;Master 1;\n"
            ."VAR-1;Variant 1;MST-1\n"
            ."VAR-2;Variant 2;MST-2\n"
            ."MST-2;Master 2;\n"
            ."VAR-3;Variant 3;GHOST-MASTER\n",
            'pim-parent-',
        );

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'parent_sku' => '__parent_sku__',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'parent.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame(5, $body['success_count'], 'all five rows create their object');
            self::assertSame('partial', $body['status'], 'the GHOST parent makes the session partial');

            $em = $this->em();
            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);

            $var1 = $repo->findByCode('VAR-1', ObjectKind::Product, $tenant);
            \assert(null !== $var1);
            self::assertSame('MST-1', $var1->getParent()?->getCode());

            $var2 = $repo->findByCode('VAR-2', ObjectKind::Product, $tenant);
            \assert(null !== $var2);
            self::assertSame('MST-2', $var2->getParent()?->getCode(), 'parent resolved though its row came AFTER');

            $var3 = $repo->findByCode('VAR-3', ObjectKind::Product, $tenant);
            \assert(null !== $var3);
            self::assertNull($var3->getParent(), 'missing parent → unparented but still imported');

            $ghostWarning = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE message LIKE '%GHOST-MASTER%'",
            );
            self::assertSame(1, (int) (\is_scalar($ghostWarning) ? $ghostWarning : 0));
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function importRelationCellCreatesObjectRelationsNotObjectValues(): void
    {
        // IMP2-1.8 (AC) — `REL-A|REL-B` in a Relation cell → 2 object_relations
        // (position 0,1), and NO ObjectValue{object_id}. Targets follow the
        // source row, so the two-pass resolves order-independently.
        $this->seedAttributes();

        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $related = new Attribute('related', ['en' => 'Related'], AttributeType::Relation);
        $related->setRelationTargetObjectTypeIds([$productOt->getId()->toRfc4122()]);
        $related->setRelationCardinality(RelationCardinality::Many);
        $em->persist($related);
        $em->persist(new ObjectTypeAttribute($productOt, $related, false, 3));
        $em->flush();

        $csvPath = $this->writeCsv(
            "sku;name;related\nSRC-1;Source;REL-A|REL-B\nREL-A;Target A;\nREL-B;Target B;\n",
            'pim-rel-',
        );

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'related' => 'related',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'rel.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('success', $body['status']);
            self::assertSame(3, $body['success_count']);

            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
            $relations = self::getContainer()->get(ObjectRelationRepositoryInterface::class);
            $relAttr = self::getContainer()->get(AttributeRepositoryInterface::class)->findByCode('related', $tenant);
            \assert(null !== $relAttr);

            $src = $repo->findByCode('SRC-1', ObjectKind::Product, $tenant);
            \assert(null !== $src);
            $links = $relations->findBySourceAndAttribute($src, $relAttr);
            self::assertCount(2, $links);
            self::assertSame(['REL-A', 'REL-B'], array_map(static fn ($l): string => $l->getTarget()->getCode(), $links));
            self::assertSame([0, 1], array_map(static fn ($l): int => $l->getPosition(), $links));

            $ovCount = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='related' AND o.code='SRC-1'",
            );
            self::assertSame(0, (int) (\is_scalar($ovCount) ? $ovCount : 1), 'a Relation cell must NOT write an ObjectValue');
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function importGalleryCellSplitsAssetsAndDropsDanglingIds(): void
    {
        // IMP2-1.8 (AC) — `id1|id2` in an Asset cell → envelope with a list;
        // a non-existent asset id → row warning, nothing dangling in JSONB.
        $this->seedAttributes();

        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $gallery = new Attribute('gallery', ['en' => 'Gallery'], AttributeType::Asset);
        $em->persist($gallery);
        $em->persist(new ObjectTypeAttribute($productOt, $gallery, false, 3));

        $assetA = new \App\Asset\Domain\Entity\Asset('AST-A', 'a.jpg', 'image/jpeg', 10, 'p/a.jpg');
        $assetB = new \App\Asset\Domain\Entity\Asset('AST-B', 'b.jpg', 'image/jpeg', 10, 'p/b.jpg');
        $em->persist($assetA);
        $em->persist($assetB);
        $em->flush();
        $idA = $assetA->getId()->toRfc4122();
        $idB = $assetB->getId()->toRfc4122();
        $bogus = '01914aaa-bbbb-7ccc-8ddd-eeeeeeeeeeee';

        $csvPath = $this->writeCsv(
            "sku;name;gallery\nGAL-1;Two assets;{$idA}|{$idB}\nGAL-2;One bad;{$idA}|{$bogus}\n",
            'pim-gal-',
        );

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'gallery' => 'gallery',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'gal.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame(2, $body['success_count']);

            // GAL-1 → both ids survive → list envelope.
            $valueOne = $em->getConnection()->fetchOne(
                "SELECT ov.value FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='gallery' AND o.code='GAL-1'",
            );
            \assert(\is_string($valueOne));
            self::assertSame(['asset_id' => [$idA, $idB]], json_decode($valueOne, true, 512, JSON_THROW_ON_ERROR));

            // GAL-2 → only the existing id survives (scalar), bogus dropped.
            $valueTwo = $em->getConnection()->fetchOne(
                "SELECT ov.value FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='gallery' AND o.code='GAL-2'",
            );
            \assert(\is_string($valueTwo));
            $decodedTwo = json_decode($valueTwo, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['asset_id' => $idA], $decodedTwo);
            self::assertStringNotContainsString($bogus, $valueTwo, 'dangling asset id must not reach JSONB');

            // The dropped id surfaced as a row warning.
            $warnings = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE level='warning' AND message LIKE '%does not exist for this tenant%'",
            );
            self::assertSame(1, (int) (\is_scalar($warnings) ? $warnings : 0));
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function mixedRowsFinishPartialWithPerRowIsolation(): void
    {
        // IMP2-1.9 (AC) — 10 rows, 2 bad (missing SKU + bad number type):
        // session ends partial, success=8, error=2, exactly 2 Error logs.
        $this->seedAttributes();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);
        $qty = new Attribute('qty', ['en' => 'Qty'], AttributeType::Number);
        $em->persist($qty);
        $em->persist(new ObjectTypeAttribute($productOt, $qty, false, 3));
        $em->flush();

        $rows = ['sku;name;qty'];
        for ($i = 1; $i <= 8; ++$i) {
            $rows[] = \sprintf('MIX-%d;Product %d;%d', $i, $i, $i);
        }
        $rows[] = ';No sku;5';           // missing SKU → Error
        $rows[] = 'MIX-BAD;Bad qty;abc'; // non-numeric → InvalidType Error
        $csvPath = $this->writeCsv(implode("\n", $rows)."\n", 'pim-mix-');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name', 'qty' => 'qty'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'mix.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('partial', $body['status']);
            self::assertSame(8, $body['success_count']);
            self::assertSame(2, $body['error_count']);

            $errorLogs = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE level='error' AND import_session_id = :id",
                ['id' => $body['id']],
            );
            self::assertSame(2, (int) (\is_scalar($errorLogs) ? $errorLogs : 0));
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function duplicateSkuInFileSkipsWithoutError(): void
    {
        // IMP2-1.9 (AC D1) — the first occurrence imports; later duplicates are
        // skipped with a Warning, never an error, and never explode the DB.
        $this->seedAttributes();
        $em = $this->em();

        $csvPath = $this->writeCsv("sku;name\nDUP-1;First\nDUP-2;Second\nDUP-1;Again\n", 'pim-dup-');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'dup.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('success', $body['status'], 'a pure skip leaves no errors → success');
            self::assertSame(2, $body['success_count']);
            self::assertSame(0, $body['error_count']);
            self::assertSame(1, $body['skipped_count']);

            $warnings = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE level='warning' AND error_type='duplicate_sku_in_file' AND import_session_id = :id",
                ['id' => $body['id']],
            );
            self::assertSame(1, (int) (\is_scalar($warnings) ? $warnings : 0));
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function identifierDuplicateVsDbBlocksRowGracefully(): void
    {
        // IMP2-1.9 (AC item 2) — an identifier value already used in the catalog
        // is caught by the set pre-check: the row errors, the session ends
        // partial, and the DB partial-unique index never throws.
        $this->seedAttributes();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $ean = new Attribute('ean', ['en' => 'EAN'], AttributeType::Identifier);
        $em->persist($ean);
        $em->persist(new ObjectTypeAttribute($productOt, $ean, false, 3));
        $existing = new CatalogObject($productOt, 'EXIST-1');
        $em->persist($existing);
        $em->flush();
        $em->persist(new \App\Catalog\Domain\Entity\ObjectValue($existing, $ean, ['value' => '111'], \App\Catalog\Domain\Provenance::Import));
        $em->flush();

        $csvPath = $this->writeCsv("sku;name;ean\nNEW-1;Collides;111\nNEW-2;Fresh;222\n", 'pim-ident-');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name', 'ean' => 'ean'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'ident.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('partial', $body['status']);
            self::assertSame(1, $body['success_count'], 'only the non-colliding row imports');
            self::assertSame(1, $body['error_count']);

            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
            self::assertNotNull($repo->findByCode('NEW-2', ObjectKind::Product, $tenant), 'fresh identifier imports');
            self::assertNull($repo->findByCode('NEW-1', ObjectKind::Product, $tenant), 'colliding identifier row is not created');
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function createModeSkipsRowsWhoseSkuAlreadyExists(): void
    {
        // IMP2-1.3 (#1465, AC) — mode CREATE only inserts new objects. A row
        // whose match key (SKU) already lives in the catalog is skipped with a
        // DuplicateSkuInDb Warning, never updated; the fresh SKU still imports.
        // Logic: ObjectResolver::decide (SkipExists) + ImportRunHandler:1481.
        $this->seedAttributes();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);
        $em->persist(new CatalogObject($productOt, 'EXIST-1'));
        $em->flush();

        $csvPath = $this->writeCsv("sku;name\nEXIST-1;X\nNEW-1;Y\n", 'pim-create-');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'mode' => 'CREATE',
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'create.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('CREATE', $body['mode']);
            self::assertSame('success', $body['status'], 'a pure skip leaves no errors → success');
            self::assertSame(1, $body['success_count'], 'only the fresh SKU is created');
            self::assertSame(1, $body['skipped_count'], 'the pre-existing SKU is skipped');
            self::assertSame(0, $body['updated_count'], 'CREATE never updates an existing object');

            // The skip is logged as a DuplicateSkuInDb Warning for EXIST-1.
            $warnings = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE level='warning' AND error_type='duplicate_sku_in_db' AND sku='EXIST-1' AND import_session_id = :id",
                ['id' => $body['id']],
            );
            self::assertSame(1, (int) (\is_scalar($warnings) ? $warnings : 0));

            // EXIST-1 is untouched; NEW-1 now exists.
            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
            self::assertNotNull($repo->findByCode('NEW-1', ObjectKind::Product, $tenant));
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function updateModeSkipsRowsWithoutAnExistingMatch(): void
    {
        // IMP2-1.3 (#1465, AC) — mode UPDATE only touches objects already in the
        // catalog. A row whose SKU matches nothing is skipped with a NoMatchInDb
        // Warning — never created, never counted as updated. Logic:
        // ObjectResolver::decide (SkipNoMatch) + ImportRunHandler:1489.
        $this->seedAttributes();
        $em = $this->em();

        $csvPath = $this->writeCsv("sku;name\nGHOST-1;X\n", 'pim-update-');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPDATE',
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'update.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('UPDATE', $body['mode']);
            self::assertSame(1, $body['skipped_count'], 'the unmatched row is skipped');
            self::assertSame(0, $body['success_count'], 'UPDATE creates nothing');
            self::assertSame(0, $body['updated_count'], 'no match → nothing updated');

            $warnings = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE level='warning' AND error_type='no_match_in_db' AND sku='GHOST-1' AND import_session_id = :id",
                ['id' => $body['id']],
            );
            self::assertSame(1, (int) (\is_scalar($warnings) ? $warnings : 0));

            // No GHOST-1 object was created.
            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
            self::assertNull($repo->findByCode('GHOST-1', ObjectKind::Product, $tenant));
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function matchByIdentifierAttributeUpdatesTheRowWithADifferentCode(): void
    {
        // IMP2-1.3 (#1465, AC) — with match_attribute_code='ean' the row is
        // matched by its identifier value, not by SKU. A CSV whose `sku` column
        // differs from the existing object's code still updates that object (the
        // Bosch EAN benchmark): no new object, the code stays put, only the
        // mapped value changes. Logic: ObjectResolver::resolve identifier branch.
        $this->seedAttributes();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        // EAN is an identifier attribute (the controller rejects a non-identifier
        // match_attribute_code with a 400).
        $ean = new Attribute('ean', ['en' => 'EAN'], AttributeType::Identifier);
        $em->persist($ean);
        $em->persist(new ObjectTypeAttribute($productOt, $ean, false, 3));
        $existing = new CatalogObject($productOt, 'OLD-SKU');
        $em->persist($existing);
        $em->flush();
        $em->persist(new \App\Catalog\Domain\Entity\ObjectValue(
            $existing,
            $ean,
            ['value' => '5901234123457'],
            \App\Catalog\Domain\Provenance::Import,
        ));
        $em->flush();

        $csvPath = $this->writeCsv(
            "sku;name;ean\nDIFFERENT-SKU;Nowa nazwa;5901234123457\n",
            'pim-eanmatch-',
        );

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name', 'ean' => 'ean'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPDATE',
                        'match_attribute_code' => 'ean',
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'eanmatch.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame(1, $body['updated_count'], 'the EAN match updates the existing object');
            self::assertSame(0, $body['skipped_count'], 'the row matched, so it is not skipped');

            $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
            \assert($tenant instanceof Tenant);
            self::getContainer()->get(TenantContext::class)->set($tenant);
            $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);

            // No new object was created from the differing SKU column.
            self::assertNull($repo->findByCode('DIFFERENT-SKU', ObjectKind::Product, $tenant), 'the differing SKU must not create a second object');
            $matched = $repo->findByCode('OLD-SKU', ObjectKind::Product, $tenant);
            self::assertNotNull($matched, 'the existing object keeps its original code');

            // Exactly one product exists (the matched one), and its name changed.
            $objectCount = $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM objects o WHERE o.object_type_id = :type',
                ['type' => $productOt->getId()->toRfc4122()],
            );
            self::assertSame(1, (int) (\is_scalar($objectCount) ? $objectCount : 0), 'only the matched object exists, no duplicate');

            $newName = $em->getConnection()->fetchOne(
                "SELECT ov.value->>'value' FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='name' AND o.code='OLD-SKU'",
            );
            self::assertSame('Nowa nazwa', \is_scalar($newName) ? (string) $newName : null);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function emptyCellOnUpdateDoesNotClearTheExistingValue(): void
    {
        // IMP2-1.3 (#1465, AC) / ADR-0019 D2 — an empty cell on an UPDATE/UPSERT
        // means "do not touch", it never clears the stored value. Re-importing
        // SKU-1 with a blank `desc` keeps the old description while still writing
        // the new name. Logic: ImportObjectCreator::buildWrites:190.
        $this->seedAttributes();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $name = self::getContainer()->get(AttributeRepositoryInterface::class)->findByCode('name', $tenant);
        \assert(null !== $name);
        $desc = new Attribute('desc', ['en' => 'Description'], AttributeType::Text);
        $em->persist($desc);
        $em->persist(new ObjectTypeAttribute($productOt, $desc, false, 3));
        $existing = new CatalogObject($productOt, 'SKU-1');
        $em->persist($existing);
        $em->flush();
        $em->persist(new \App\Catalog\Domain\Entity\ObjectValue($existing, $name, ['value' => 'Stara'], \App\Catalog\Domain\Provenance::Import));
        $em->persist(new \App\Catalog\Domain\Entity\ObjectValue($existing, $desc, ['value' => 'Opis'], \App\Catalog\Domain\Provenance::Import));
        $em->flush();

        $csvPath = $this->writeCsv("sku;name;desc\nSKU-1;Nowa;\n", 'pim-emptycell-');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name', 'desc' => 'desc'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'emptycell.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame(1, $body['updated_count'], 'the name change makes it a real update');

            $storedName = $em->getConnection()->fetchOne(
                "SELECT ov.value->>'value' FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='name' AND o.code='SKU-1'",
            );
            self::assertSame('Nowa', \is_scalar($storedName) ? (string) $storedName : null, 'the non-empty cell overwrites');

            $storedDesc = $em->getConnection()->fetchOne(
                "SELECT ov.value->>'value' FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='desc' AND o.code='SKU-1'",
            );
            self::assertSame('Opis', \is_scalar($storedDesc) ? (string) $storedDesc : null, 'the empty cell must NOT clear the stored description');
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function oversizedFileIsRejectedWith422(): void
    {
        // IMP2-2.7 (#1483) — a per-tenant file-size limit below the upload size
        // yields a clean RFC 7807 422, not the raw PHP truncation.
        $this->seedAttributes();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $tenant->setImportMaxFileSize(10); // 10 bytes — any real CSV exceeds it
        $em->flush();

        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function tooManyDataRowsIsRejectedWith422(): void
    {
        // IMP2-2.7 (#1483) — a per-tenant row limit rejects an oversized file
        // before any persistence.
        $this->seedAttributes();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $tenant->setImportMaxRows(5);
        $em->flush();

        $rows = ['sku;name'];
        for ($i = 1; $i <= 10; ++$i) {
            $rows[] = \sprintf('OVER-%d;Product %d', $i, $i);
        }
        $csvPath = $this->writeCsv(implode("\n", $rows)."\n", 'pim-rows-');
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'over.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function rateLimitExceededReturns429(): void
    {
        // IMP2-2.7 (#1483) — exhausting the per-tenant import_trigger window
        // (limit 20/h) makes the next start return 429.
        $this->seedAttributes();
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $limiter = self::getContainer()->get('limiter.import_trigger')->create($tenant->getId()->toRfc4122());
        for ($i = 0; $i < 20; ++$i) {
            $limiter->consume();
        }

        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(429);
        } finally {
            // The limiter storage is shared (cache pool), so drain-then-leave
            // would 429 every later import test for this tenant. Reset it.
            $limiter->reset();
            @unlink($csvPath);
        }
    }

    #[Test]
    public function malformedXlsxArchiveIsRejectedWith422(): void
    {
        // IMP2-2.8 (#1484) — XlsxArchiveGuard rejects a file that is not a valid
        // ZIP (every real .xlsx is one) before the parser touches it: 422, not 500.
        $this->seedAttributes();
        $tmp = tempnam(sys_get_temp_dir(), 'pim-bomb-');
        \assert(false !== $tmp);
        $xlsxPath = $tmp.'.xlsx';
        rename($tmp, $xlsxPath);
        file_put_contents($xlsxPath, 'this is definitely not a zip archive');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile(
                        $xlsxPath,
                        'bomb.xlsx',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        null,
                        true,
                    )],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            @unlink($xlsxPath);
        }
    }

    #[Test]
    public function backupRequestedWithCompletedBackupLinksItToSession(): void
    {
        // IMP2-2.10 (#1486) — do_backup=1 + a completed backup_id → the session
        // records the snapshot, the start response and the GET both expose it.
        $this->seedAttributes();
        $backupId = $this->seedBackup(BackupStatus::Completed);
        $csvPath = $this->writeSmallCsv();

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'do_backup' => '1',
                        'backup_id' => $backupId,
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertIsArray($body['backup'] ?? null, 'start response carries the linked backup');
            self::assertSame($backupId, $body['backup']['id']);
            self::assertSame('completed', $body['backup']['status']);

            // The GET endpoint exposes the same backup.
            self::assertIsString($body['id']);
            $client->request('GET', '/api/import-sessions/'.$body['id']);
            self::assertResponseIsSuccessful();
            $show = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($show);
            self::assertIsArray($show['backup'] ?? null);
            self::assertSame($backupId, $show['backup']['id']);

            // And the FK column is actually persisted.
            $linked = $this->em()->getConnection()->fetchOne(
                'SELECT backup_snapshot_id FROM import_sessions WHERE id = :id',
                ['id' => $body['id']],
            );
            self::assertSame($backupId, \is_scalar($linked) ? (string) $linked : null);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function backupRequestedWithoutBackupIdIs422(): void
    {
        $this->seedAttributes();
        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'do_backup' => '1',
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function backupRequestedWithIncompleteBackupIs422(): void
    {
        // A still-running snapshot must not be accepted as the pre-import backup.
        $this->seedAttributes();
        $backupId = $this->seedBackup(BackupStatus::Running);
        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'do_backup' => '1',
                        'backup_id' => $backupId,
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function backupRequestedWithPendingBackupIs422(): void
    {
        // IMP2-2.10 (#1559) — a queued snapshot that has not started yet is not
        // a valid pre-import backup; the wizard must wait for `completed`.
        $this->seedAttributes();
        $backupId = $this->seedBackup(BackupStatus::Pending);
        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'do_backup' => '1',
                        'backup_id' => $backupId,
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function backupRequestedWithFailedBackupIs422(): void
    {
        // IMP2-2.10 (#1559) — a failed snapshot must not be accepted; the import
        // would otherwise run unprotected.
        $this->seedAttributes();
        $backupId = $this->seedBackup(BackupStatus::Failed);
        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'do_backup' => '1',
                        'backup_id' => $backupId,
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function unknownBackupIdIs404(): void
    {
        $this->seedAttributes();
        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'do_backup' => '1',
                        'backup_id' => Uuid::v7()->toRfc4122(),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(404);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function backupFromAnotherTenantIs404(): void
    {
        // Tenant isolation — a completed backup owned by a different tenant must
        // not be linkable; 404 (no existence leak), never 200.
        $this->seedAttributes();
        $backupId = $this->seedForeignTenantBackup();
        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'do_backup' => '1',
                        'backup_id' => $backupId,
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseStatusCodeSame(404);
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function noBackupRequestedLeavesBackupNull(): void
    {
        // Regression — do_backup absent → session starts as before, backup null.
        $this->seedAttributes();
        $csvPath = $this->writeSmallCsv();
        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => ['file' => new UploadedFile($csvPath, 'small.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertNull($body['backup'], 'no backup requested → backup is null');
        } finally {
            @unlink($csvPath);
        }
    }

    private function seedBackup(BackupStatus $status): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $backup = new Backup(Uuid::v7(), BackupTriggerAction::PreImport);
        $backup->assignTenant($tenant);
        if (BackupStatus::Running === $status || BackupStatus::Completed === $status) {
            $backup->markRunning();
        }
        if (BackupStatus::Completed === $status) {
            $backup->markCompleted(1024, 'test-label');
        }
        self::getContainer()->get(BackupRepositoryInterface::class)->save($backup);

        return $backup->getId()->toRfc4122();
    }

    private function seedForeignTenantBackup(): string
    {
        $em = $this->em();
        $other = new Tenant('other-'.bin2hex(random_bytes(4)), 'Other Tenant');
        $em->persist($other);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($other);

        $backup = new Backup(Uuid::v7(), BackupTriggerAction::PreImport);
        $backup->assignTenant($other);
        $backup->markRunning();
        $backup->markCompleted(1024, 'foreign');
        self::getContainer()->get(BackupRepositoryInterface::class)->save($backup);

        return $backup->getId()->toRfc4122();
    }

    private function writeCsv(string $contents, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        \assert(false !== $path);
        $renamed = $path.'.csv';
        rename($path, $renamed);
        file_put_contents($renamed, $contents);

        return $renamed;
    }

    private function seedAttributes(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
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

    private function writeSmallCsv(): string
    {
        $rows = ['sku;name'];
        for ($i = 1; $i <= 5; ++$i) {
            $rows[] = \sprintf('SMALL-%d;Product %d', $i, $i);
        }
        $contents = implode("\n", $rows)."\n";

        $path = tempnam(sys_get_temp_dir(), 'pim-start-import-');
        \assert(false !== $path);
        $renamed = $path.'.csv';
        rename($path, $renamed);
        file_put_contents($renamed, $contents);

        return $renamed;
    }

    private function writeCategoryCsv(): string
    {
        $contents = "sku;name;kategoria\n"
            ."CAT-1;Czujnik;pneumatyka\n"
            ."CAT-2;Sterownik;elektronika\n"
            ."CAT-3;Bez kategorii;nonexistent-code\n";

        $path = tempnam(sys_get_temp_dir(), 'pim-start-import-cat-');
        \assert(false !== $path);
        $renamed = $path.'.csv';
        rename($path, $renamed);
        file_put_contents($renamed, $contents);

        return $renamed;
    }

    /**
     * @param list<string> $codes
     */
    private function seedCategories(array $codes): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $categoryType = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Category, $tenant);
        \assert($categoryType instanceof ObjectType);

        foreach ($codes as $code) {
            $category = new CatalogObject($categoryType, $code);
            $category->attachToPath($code);
            $em->persist($category);
        }
        $em->flush();
    }
}
