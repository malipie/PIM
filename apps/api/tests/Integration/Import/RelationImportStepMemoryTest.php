<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\RelationCardinality;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectRelationRepositoryInterface;
use App\Import\Application\Service\RelationImportStep;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AUD-069 (W3-5.3) — regression guard for {@see RelationImportStep}'s memory
 * posture in pass 2 (relation wiring).
 *
 * Two unbounded structures were the OOM risk on a dense 50k import:
 *   1. the per-window `$seenTriples` dedupe set — now CLEARED on every flush
 *      ({@see RelationImportStep::resolveRelations}), so it stays O(one chunk)
 *      instead of O(total relations);
 *   2. the pass-1 link buffers, which can't be flushed incrementally (two-pass
 *      design) — now guarded by a fail-loud cap so a runaway fan-out aborts
 *      readably instead of SIGKILL-ing the worker.
 *
 * The first test is the correctness half: it declares the SAME triple across
 * more rows than one flush chunk (CHUNK = 200), forcing several flush+clear
 * cycles, and asserts the catalog ends with exactly ONE relation row. That
 * proves clearing the in-memory set does NOT regress dedup — once a chunk is
 * flushed, the DQL `findBySourceAndAttribute` read re-detects the persisted
 * triple, so the cleared set is genuinely redundant.
 */
final class RelationImportStepMemoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function duplicateTripleAcrossFlushChunksStillDedupesToOneRelation(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $em = $this->em();

        $type = $this->productObjectType($em);
        $typeId = $type->getId()->toRfc4122();

        $source = new CatalogObject($type, 'SRC');
        $target = new CatalogObject($type, 'TGT');
        $em->persist($source);
        $em->persist($target);

        $attr = new Attribute('related', ['en' => 'Related'], AttributeType::Relation);
        $attr->setRelationTargetObjectTypeIds([$typeId]);
        $attr->setRelationCardinality(RelationCardinality::Many);
        $em->persist($attr);
        $em->flush();
        $em->clear();

        $step = $this->step();
        $step->reset();

        // Declare the identical (SRC, related, TGT) triple on 450 rows — more
        // than 2× CHUNK (200), so resolveRelations flushes+clears the dedupe
        // set at least twice while still walking the same triple. Pre-fix this
        // relied on the set never being cleared; post-fix the DQL existing-
        // targets read must carry dedup across the flush boundary.
        for ($row = 1; $row <= 450; ++$row) {
            $step->recordRelation('SRC', 'related', ['TGT'], $row);
        }

        $errors = $step->resolve(ObjectKind::Product, $tenant);
        self::assertSame([], $errors, 'a valid self-consistent relation must not error');

        // tenant-safe: single-tenant integration test; the only tenant in the
        // schema is `alpha`, so an unfiltered COUNT cannot read another tenant.
        $count = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_relations');
        self::assertSame(
            1,
            (int) (\is_scalar($count) ? $count : -1),
            'the same triple declared across many flush chunks must collapse to exactly one relation row',
        );
    }

    #[Test]
    public function duplicateTargetsWithinOneCellDedupeBeforeFlush(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $em = $this->em();

        $type = $this->productObjectType($em);
        $typeId = $type->getId()->toRfc4122();

        $source = new CatalogObject($type, 'SRC');
        $target = new CatalogObject($type, 'TGT');
        $em->persist($source);
        $em->persist($target);

        $attr = new Attribute('related', ['en' => 'Related'], AttributeType::Relation);
        $attr->setRelationTargetObjectTypeIds([$typeId]);
        $attr->setRelationCardinality(RelationCardinality::Many);
        $em->persist($attr);
        $em->flush();
        $em->clear();

        $step = $this->step();
        $step->reset();
        // Same triple twice in ONE flush window (well under CHUNK) — the
        // in-memory $seenTriples set is what catches this before any flush.
        $step->recordRelation('SRC', 'related', ['TGT'], 1);
        $step->recordRelation('SRC', 'related', ['TGT'], 2);

        $errors = $step->resolve(ObjectKind::Product, $tenant);
        self::assertSame([], $errors);

        // tenant-safe: single-tenant schema (only `alpha`).
        $count = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_relations');
        self::assertSame(1, (int) (\is_scalar($count) ? $count : -1), 'in-window duplicate triple must dedupe to one row');
    }

    #[Test]
    public function bufferCapAbortsRunawayFanOutBeforeOom(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);

        // A RelationImportStep wired with a tiny cap (3) — the prod default is
        // 1,000,000; the small value lets us prove the fail-loud guard without
        // buffering a million tuples in the test.
        $step = new RelationImportStep(
            self::getContainer()->get(CatalogObjectRepositoryInterface::class),
            self::getContainer()->get(ObjectRelationRepositoryInterface::class),
            self::getContainer()->get(AttributeRepositoryInterface::class),
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(EntityManagerInterface::class),
            maxBufferedLinks: 3,
        );
        $step->reset();

        $step->recordRelation('SRC', 'related', ['T1'], 1);
        $step->recordParent('SRC', 'M1', 2);
        $step->recordRelation('SRC', 'related', ['T2'], 3);

        // The 4th buffered link crosses the cap (3) → fail loud, no silent OOM.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/relation buffer exceeded 3 links/');
        $step->recordRelation('SRC', 'related', ['T3'], 4);
    }

    private function step(): RelationImportStep
    {
        return self::getContainer()->get(RelationImportStep::class);
    }

    private function productObjectType(EntityManagerInterface $em): ObjectType
    {
        $type = $em->getRepository(ObjectType::class)->findOneBy(['kind' => ObjectKind::Product]);
        if ($type instanceof ObjectType) {
            return $type;
        }

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $em->flush();

        return $type;
    }

    private function activateTenantFilter(Tenant $tenant): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
    }

    private function createTenant(string $code): Tenant
    {
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
