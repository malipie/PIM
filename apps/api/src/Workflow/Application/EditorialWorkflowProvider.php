<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\Shared\Application\TenantContext;
use App\Workflow\Contracts\EditorialWorkflowProviderInterface;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use App\Workflow\Contracts\TaskAssignee;
use App\Workflow\Domain\Entity\WorkflowDefinition;
use Doctrine\ORM\EntityManagerInterface;
use SplObjectStorage;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * WFL-P5-01 (#2431) — single source of "which machine governs this
 * object" (ADR-0029 pillar 7). Flag OFF (default) or no enabled tenant
 * definition → the static YAML `object_editorial` machine. Flag ON →
 * the tenant's enabled DB definition (ObjectType-specific beats
 * global) built at runtime with per-transition METADATA (permission,
 * comment_required, completeness_gate) that the guards consume.
 *
 * The runtime machine keeps the `object_editorial` NAME and the shared
 * event dispatcher, so every existing listener (permission guard,
 * completeness gate, transition log, event recorder) fires unchanged.
 *
 * Worker-mode cache: definitions are cached per (tenant, updatedAt)
 * key — an edited definition changes updatedAt, so a stale FrankenPHP
 * worker rebuilds on the next request without a restart (bounded map,
 * last 16 entries).
 */
final class EditorialWorkflowProvider implements EditorialWorkflowProviderInterface
{
    /** @var array<string, Workflow> */
    private array $cache = [];

    public function __construct(
        #[Target(ObjectEditorialWorkflow::NAME)]
        private readonly WorkflowInterface $staticWorkflow,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly bool $customDefinitionsEnabled = false,
    ) {
    }

    public function for(object $subject, ?string $objectTypeId = null): WorkflowInterface
    {
        unset($subject);
        $definition = $this->governingDefinition($objectTypeId);
        if (null === $definition) {
            return $this->staticWorkflow;
        }

        $cacheKey = $definition->getId()->toRfc4122().'@'.$definition->getUpdatedAt()->format('U.u');
        if (!isset($this->cache[$cacheKey])) {
            if (\count($this->cache) >= 16) {
                $this->cache = [];
            }
            $this->cache[$cacheKey] = $this->build($definition);
        }

        return $this->cache[$cacheKey];
    }

    public function reviewerFor(?string $objectTypeId): ?TaskAssignee
    {
        $definition = $this->governingDefinition($objectTypeId);
        if (null === $definition) {
            return null;
        }

        $reviewer = $definition->getReviewer();
        if (null === $reviewer) {
            return null;
        }

        return TaskAssignee::fromDefinition($reviewer);
    }

    /**
     * The enabled tenant definition that governs this ObjectType, or null
     * when custom definitions are off / there is no tenant / no enabled
     * definition applies (callers fall back to the static machine).
     */
    private function governingDefinition(?string $objectTypeId): ?WorkflowDefinition
    {
        if (!$this->customDefinitionsEnabled) {
            return null;
        }

        if (null === $this->tenantContext->get()) {
            return null;
        }

        return $this->resolveDefinition($objectTypeId);
    }

    private function resolveDefinition(?string $objectTypeId): ?WorkflowDefinition
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(WorkflowDefinition::class, 'd')
            ->where('d.enabled = true')
            ->orderBy('d.updatedAt', 'DESC');

        /** @var list<WorkflowDefinition> $rows */
        $rows = $builder->getQuery()->getResult();
        if ([] === $rows) {
            return null;
        }

        // ObjectType-specific definition wins over the tenant-global one.
        foreach ($rows as $definition) {
            if (null !== $definition->getObjectTypeId()
                && null !== $objectTypeId
                && $definition->getObjectTypeId()->toRfc4122() === $objectTypeId) {
                return $definition;
            }
        }
        foreach ($rows as $definition) {
            if (null === $definition->getObjectTypeId()) {
                return $definition;
            }
        }

        return null;
    }

    private function build(WorkflowDefinition $definition): Workflow
    {
        $places = [];
        foreach ($definition->getPlaces() as $place) {
            if (\is_string($place['name'] ?? null)) {
                $places[] = $place['name'];
            }
        }

        $transitions = [];
        /** @var SplObjectStorage<Transition, array<string, mixed>> $transitionsMetadata */
        $transitionsMetadata = new SplObjectStorage();
        foreach ($definition->getTransitions() as $entry) {
            $name = $entry['name'] ?? null;
            $to = $entry['to'] ?? null;
            if (!\is_string($name) || !\is_string($to)) {
                continue;
            }
            $froms = $entry['from'] ?? [];
            $froms = \is_string($froms) ? [$froms] : (\is_array($froms) ? \array_filter($froms, \is_string(...)) : []);

            $transition = new Transition($name, \array_values($froms), [$to]);
            $transitions[] = $transition;

            $metadata = [];
            if (\is_string($entry['permission'] ?? null)) {
                $metadata['permission'] = $entry['permission'];
            }
            if (true === ($entry['comment_required'] ?? false)) {
                $metadata['comment_required'] = true;
            }
            if (\is_array($entry['completeness_gate'] ?? null)) {
                $metadata['completeness_gate'] = $entry['completeness_gate'];
            }
            if ([] !== $metadata) {
                $transitionsMetadata[$transition] = $metadata;
            }
        }

        $workflowDefinition = new DefinitionBuilder($places, $transitions)
            ->setInitialPlaces([WorkflowDefinitionValidator::INITIAL_PLACE])
            ->setMetadataStore(new InMemoryMetadataStore(
                ['definition_id' => $definition->getId()->toRfc4122()],
                [],
                // @phpstan-ignore argument.type (SplObjectStorage is invariant; value type is a narrowed array)
                $transitionsMetadata,
            ))
            ->build();

        return new Workflow(
            $workflowDefinition,
            new MethodMarkingStore(true, 'editorialMarking'),
            $this->eventDispatcher,
            ObjectEditorialWorkflow::NAME,
        );
    }
}
