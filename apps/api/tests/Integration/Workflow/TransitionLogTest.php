<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use App\Workflow\Contracts\TransitionLogPort;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * WFL-P0-04 (#2413) — the `workflow_transitions` log against real
 * Postgres: every applied transition leaves a row with the ACTUAL
 * source place (archive declares two froms), comments ride the apply
 * context, and the DoD tenant-isolation smoke (cross-read = 0) holds
 * on the TransitionLogPort surface.
 */
final class TransitionLogTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function appliedTransitionLeavesLogRowWithActualFromPlace(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $object = $this->createObject($tenant, 'WFL-LOG-1');

        $workflow = $this->workflow($object);
        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_PUBLISH, ['comment' => '  Ship it  ']);
        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_ARCHIVE);
        $this->em()->flush();

        $entries = self::getContainer()->get(TransitionLogPort::class)
            ->pageForObject($object->getId(), 10);

        self::assertCount(2, $entries);

        // Newest first: archive left "published" — the actual place, not
        // the two-element declaration "draft,published" from the config.
        self::assertSame(ObjectEditorialWorkflow::TRANSITION_ARCHIVE, $entries[0]->transition);
        self::assertSame(CatalogObject::STATUS_PUBLISHED, $entries[0]->fromPlace);
        self::assertSame(CatalogObject::STATUS_ARCHIVED, $entries[0]->toPlace);
        self::assertNull($entries[0]->comment);

        self::assertSame(ObjectEditorialWorkflow::TRANSITION_PUBLISH, $entries[1]->transition);
        self::assertSame(CatalogObject::STATUS_DRAFT, $entries[1]->fromPlace);
        self::assertSame('Ship it', $entries[1]->comment, 'comment must be trimmed and stored');
        self::assertNull($entries[1]->actorUserId, 'CLI/system apply has no actor');

        $latest = self::getContainer()->get(TransitionLogPort::class)
            ->latestForObject($object->getId(), ObjectEditorialWorkflow::TRANSITION_PUBLISH);
        self::assertNotNull($latest);
        self::assertSame('Ship it', $latest->comment);
    }

    #[Test]
    public function logIsIsolatedByTenant(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');

        $this->activateTenantFilter($alpha);
        $object = $this->createObject($alpha, 'WFL-LOG-ISO');
        $this->workflow($object)->apply($object, ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW);
        $this->em()->flush();

        $port = self::getContainer()->get(TransitionLogPort::class);
        self::assertCount(1, $port->pageForObject($object->getId(), 10));

        $this->activateTenantFilter($beta);
        self::assertCount(0, $port->pageForObject($object->getId(), 10), 'cross-tenant read must return 0 rows');
        self::assertNull($port->latestForObject($object->getId(), ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW));
    }

    private function workflow(CatalogObject $object): WorkflowInterface
    {
        return self::getContainer()->get(Registry::class)->get($object, ObjectEditorialWorkflow::NAME);
    }

    private function createObject(Tenant $tenant, string $code): CatalogObject
    {
        $em = $this->em();
        $type = new ObjectType('product_'.$code, ObjectKind::Product, ['pl' => 'Produkt']);
        $type->assignTenant($tenant);
        $em->persist($type);

        $object = new CatalogObject($type, $code);
        $object->assignTenant($tenant);
        $em->persist($object);
        $em->flush();

        return $object;
    }

    private function createTenant(string $code): Tenant
    {
        $tenant = new Tenant($code, \ucfirst($code).' Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function activateTenantFilter(Tenant $tenant): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
