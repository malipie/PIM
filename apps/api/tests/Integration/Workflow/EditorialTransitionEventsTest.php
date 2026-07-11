<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Catalog\Contracts\Event\ObjectPublished;
use App\Catalog\Contracts\Event\ObjectRejected;
use App\Catalog\Contracts\Event\ObjectSubmittedForReview;
use App\Catalog\Contracts\Event\ObjectUnpublished;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * WFL-P2-01 (#2420) — every editorial transition leaves the right
 * aggregate event with its context: recorder-sourced events for
 * transitions the marking writer cannot distinguish (submit/reject/
 * unpublish/restore) and writer-sourced ObjectPublished/ObjectArchived
 * stay single-sourced (no duplicates on publish).
 */
final class EditorialTransitionEventsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function editorialLoopRecordsTransitionSpecificEvents(): void
    {
        $em = $this->em();
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $type->assignTenant($tenant);
        $em->persist($type);
        $object = new CatalogObject($type, 'WFL-EVENTS-1');
        $object->assignTenant($tenant);
        $em->persist($object);
        $em->flush();

        $workflow = self::getContainer()->get(Registry::class)->get($object, ObjectEditorialWorkflow::NAME);
        $object->pullEvents();

        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW, ['comment' => 'Do przeglądu']);
        $events = $object->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ObjectSubmittedForReview::class, $events[0]);
        self::assertSame('Do przeglądu', $events[0]->comment);

        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_REJECT, ['comment' => 'Braki w opisie']);
        $events = $object->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ObjectRejected::class, $events[0]);
        self::assertSame('Braki w opisie', $events[0]->comment);

        // publish → exactly ONE ObjectPublished (writer-sourced, the
        // recorder must not double it).
        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_PUBLISH);
        $events = $object->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ObjectPublished::class, $events[0]);

        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_UNPUBLISH, ['auto_unpublish' => true]);
        $events = $object->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ObjectUnpublished::class, $events[0]);
        self::assertTrue($events[0]->autoUnpublish);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
