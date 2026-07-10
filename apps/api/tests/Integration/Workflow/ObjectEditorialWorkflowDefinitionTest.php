<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;

/**
 * WFL-P0-03 (#2412) — pins the `object_editorial` state-machine topology
 * from ADR-0029. Every allowed AND forbidden (from → transition) pair is
 * asserted so a config edit that widens or narrows the graph fails here,
 * not in production. Guards (RBAC permission map) land in WFL-P1-01 and
 * get their own matrix; this test runs guard-free topology only.
 */
final class ObjectEditorialWorkflowDefinitionTest extends KernelTestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function transitionMatrix(): array
    {
        return [
            'draft' => [CatalogObject::STATUS_DRAFT, [
                ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW,
                ObjectEditorialWorkflow::TRANSITION_PUBLISH,
                ObjectEditorialWorkflow::TRANSITION_ARCHIVE,
            ]],
            'review' => [CatalogObject::STATUS_REVIEW, [
                ObjectEditorialWorkflow::TRANSITION_APPROVE,
                ObjectEditorialWorkflow::TRANSITION_REJECT,
            ]],
            'published' => [CatalogObject::STATUS_PUBLISHED, [
                ObjectEditorialWorkflow::TRANSITION_UNPUBLISH,
                ObjectEditorialWorkflow::TRANSITION_ARCHIVE,
            ]],
            'archived' => [CatalogObject::STATUS_ARCHIVED, [
                ObjectEditorialWorkflow::TRANSITION_RESTORE,
            ]],
        ];
    }

    /**
     * @param list<string> $allowed
     */
    #[Test]
    #[DataProvider('transitionMatrix')]
    public function eachPlaceEnablesExactlyItsTransitions(string $status, array $allowed): void
    {
        $workflow = $this->workflow();
        $object = $this->objectInStatus($status);

        foreach (ObjectEditorialWorkflow::TRANSITIONS as $transition) {
            self::assertSame(
                \in_array($transition, $allowed, true),
                $workflow->can($object, $transition),
                \sprintf('Transition "%s" from "%s" diverges from the ADR-0029 topology.', $transition, $status),
            );
        }
    }

    #[Test]
    public function applyMovesMarkingThroughTheEditorialLoop(): void
    {
        $workflow = $this->workflow();
        $object = $this->objectInStatus(CatalogObject::STATUS_DRAFT);

        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW);
        self::assertSame(CatalogObject::STATUS_REVIEW, $object->getStatus());

        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_APPROVE);
        self::assertSame(CatalogObject::STATUS_PUBLISHED, $object->getStatus());

        $workflow->apply($object, ObjectEditorialWorkflow::TRANSITION_UNPUBLISH);
        self::assertSame(CatalogObject::STATUS_DRAFT, $object->getStatus());
    }

    #[Test]
    public function definitionExposesExactlyTheContractPlacesAndTransitions(): void
    {
        $definition = $this->workflow()->getDefinition();

        self::assertEqualsCanonicalizing(ObjectEditorialWorkflow::PLACES, $definition->getPlaces());

        $names = \array_map(
            static fn ($transition): string => $transition->getName(),
            $definition->getTransitions(),
        );
        self::assertEqualsCanonicalizing(ObjectEditorialWorkflow::TRANSITIONS, \array_values(\array_unique($names)));
    }

    private function workflow(): \Symfony\Component\Workflow\WorkflowInterface
    {
        $registry = self::getContainer()->get(Registry::class);

        return $registry->get($this->objectInStatus(CatalogObject::STATUS_DRAFT), ObjectEditorialWorkflow::NAME);
    }

    private function objectInStatus(string $status): CatalogObject
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'WFL-TOPOLOGY-1');
        $object->forceStatus($status);

        return $object;
    }
}
