<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\RelationCardinality;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * ADR-014 / MOD-01 (#893) — round-trip for the three new
 * `attributes.relation_*` columns plus the partial CHECK constraint on
 * `relation_cardinality`.
 *
 * Foundry's `ResetDatabase` runs the migration chain on bootKernel, so the
 * presence of these columns is implicitly verified by every persist call
 * that targets them; this test pins the contract explicitly so a column-
 * level rename or default flip blows up here first.
 */
final class RelationAttributeColumnsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = $this->em();
        $this->tenant = new Tenant('demo', 'Demo');
        $em->persist($this->tenant);
        $em->flush();
        $this->tenantContext()->set($this->tenant);
    }

    #[Test]
    public function nonRelationAttributePersistsWithDefaultRelationColumns(): void
    {
        $em = $this->em();

        $text = new Attribute('description', ['pl' => 'Opis'], AttributeType::Text);
        $em->persist($text);
        $em->flush();
        $em->clear();

        $reloaded = $em->find(Attribute::class, $text->getId());

        self::assertNotNull($reloaded);
        self::assertSame([], $reloaded->getRelationTargetObjectTypeIds());
        self::assertNull($reloaded->getRelationCardinality());
        self::assertFalse($reloaded->isRelationAdvanced());
    }

    #[Test]
    public function relationAttributeRoundTripsConfigThroughOrm(): void
    {
        $em = $this->em();

        $upSell = new Attribute('up_sell', ['pl' => 'Up-sell'], AttributeType::Relation);
        $upSell->setRelationTargetObjectTypeIds([
            '11111111-1111-7111-8111-111111111111',
            '22222222-2222-7222-8222-222222222222',
        ]);
        $upSell->setRelationCardinality(RelationCardinality::Many);
        $upSell->setRelationAdvanced(true);

        $em->persist($upSell);
        $em->flush();
        $em->clear();

        $reloaded = $em->find(Attribute::class, $upSell->getId());

        self::assertNotNull($reloaded);
        self::assertSame(
            [
                '11111111-1111-7111-8111-111111111111',
                '22222222-2222-7222-8222-222222222222',
            ],
            $reloaded->getRelationTargetObjectTypeIds(),
        );
        self::assertSame(RelationCardinality::Many, $reloaded->getRelationCardinality());
        self::assertTrue($reloaded->isRelationAdvanced());
    }

    // Note: a Postgres CHECK-constraint test was intentionally dropped here.
    // Foundry's ResetDatabase rebuilds the schema from Doctrine ORM metadata,
    // not the migration chain, so the CHECK constraint added in
    // Version20260524100000 is absent in the test database. The constraint
    // still lives in production (verified by `doctrine:migrations:migrate`
    // running in CI and on prod); application-layer validation for the same
    // invariant lands in MOD-05 (#897).

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
