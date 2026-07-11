<?php

declare(strict_types=1);

namespace App\Workflow\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Workflow\Application\WorkflowDefinitionValidator;
use App\Workflow\Domain\Entity\WorkflowDefinition;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * WFL-P5-02 (#2432) — CRUD for tenant workflow definitions (ADR-0029
 * pillar 7), gated `workflow.manage_definitions` (Owner/Admin). Every
 * write runs the WorkflowDefinitionValidator; violations come back as
 * 422 with field-scoped errors for the builder UI (WFL-P5-03). The
 * runtime effect is guarded by WORKFLOW_CUSTOM_DEFINITIONS — with the
 * flag off, definitions are inert configuration.
 */
final class WorkflowDefinitionsController
{
    private const string UUID_REQUIREMENT = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowDefinitionValidator $validator,
    ) {
    }

    #[Route(path: '/api/workflow/definitions', name: 'workflow_definitions_list', methods: ['GET'])]
    #[RequiresPermission(module: 'workflow', action: 'manage_definitions')]
    public function list(): JsonResponse
    {
        /** @var list<WorkflowDefinition> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(WorkflowDefinition::class, 'd')
            ->orderBy('d.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return new JsonResponse(['items' => \array_map(self::serialize(...), $rows)]);
    }

    #[Route(
        path: '/api/workflow/definitions/{id}',
        name: 'workflow_definitions_get',
        requirements: ['id' => self::UUID_REQUIREMENT],
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'workflow', action: 'manage_definitions')]
    public function get(string $id): JsonResponse
    {
        return new JsonResponse(self::serialize($this->load($id)));
    }

    #[Route(path: '/api/workflow/definitions', name: 'workflow_definitions_create', methods: ['POST'])]
    #[RequiresPermission(module: 'workflow', action: 'manage_definitions')]
    public function create(Request $request): JsonResponse
    {
        [$name, $places, $transitions, $objectTypeId] = $this->parseShape($request);
        $this->assertValid($places, $transitions);

        $definition = new WorkflowDefinition($name, $places, $transitions, $objectTypeId);
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        return new JsonResponse(self::serialize($definition), 201);
    }

    #[Route(
        path: '/api/workflow/definitions/{id}',
        name: 'workflow_definitions_update',
        requirements: ['id' => self::UUID_REQUIREMENT],
        methods: ['PUT'],
    )]
    #[RequiresPermission(module: 'workflow', action: 'manage_definitions')]
    public function update(string $id, Request $request): JsonResponse
    {
        $definition = $this->load($id);
        [$name, $places, $transitions, $objectTypeId] = $this->parseShape($request);

        $previousPlaces = [];
        foreach ($definition->getPlaces() as $place) {
            if (\is_string($place['name'] ?? null)) {
                $previousPlaces[] = $place['name'];
            }
        }
        $this->assertValid($places, $transitions, $definition->isEnabled() ? $previousPlaces : []);

        $definition->updateShape($name, $places, $transitions, $objectTypeId);
        $this->entityManager->flush();

        return new JsonResponse(self::serialize($definition));
    }

    #[Route(
        path: '/api/workflow/definitions/{id}/enable',
        name: 'workflow_definitions_enable',
        requirements: ['id' => self::UUID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'workflow', action: 'manage_definitions')]
    public function enable(string $id): JsonResponse
    {
        $definition = $this->load($id);
        $definition->setEnabled(true);
        $this->entityManager->flush();

        return new JsonResponse(self::serialize($definition));
    }

    #[Route(
        path: '/api/workflow/definitions/{id}/disable',
        name: 'workflow_definitions_disable',
        requirements: ['id' => self::UUID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'workflow', action: 'manage_definitions')]
    public function disable(string $id): JsonResponse
    {
        $definition = $this->load($id);
        $definition->setEnabled(false);
        $this->entityManager->flush();

        return new JsonResponse(self::serialize($definition));
    }

    private function load(string $id): WorkflowDefinition
    {
        $definition = $this->entityManager->find(WorkflowDefinition::class, Uuid::fromString($id));
        if (null === $definition) {
            throw new NotFoundHttpException('Workflow definition not found.');
        }

        return $definition;
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: ?Uuid}
     */
    private function parseShape(Request $request): array
    {
        /** @var array<string, mixed> $body */
        $body = \json_decode($request->getContent(), true) ?? [];

        $name = $body['name'] ?? null;
        if (!\is_string($name) || '' === \trim($name) || \mb_strlen($name) > 128) {
            throw new BadRequestHttpException('name is required (max 128 chars).');
        }

        $places = $this->listOfMaps($body['places'] ?? null, 'places');
        $transitions = $this->listOfMaps($body['transitions'] ?? null, 'transitions');

        $objectTypeId = null;
        if (\is_string($body['object_type_id'] ?? null) && Uuid::isValid($body['object_type_id'])) {
            $objectTypeId = Uuid::fromString($body['object_type_id']);
        }

        return [\trim($name), $places, $transitions, $objectTypeId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfMaps(mixed $value, string $field): array
    {
        if (!\is_array($value) || [] === $value) {
            throw new BadRequestHttpException(\sprintf('%s must be a non-empty list.', $field));
        }
        $result = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                throw new BadRequestHttpException(\sprintf('%s entries must be objects.', $field));
            }
            $typed = [];
            foreach ($entry as $key => $item) {
                $typed[(string) $key] = $item;
            }
            $result[] = $typed;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $places
     * @param list<array<string, mixed>> $transitions
     * @param list<string>               $previousPlaces
     */
    private function assertValid(array $places, array $transitions, array $previousPlaces = []): void
    {
        $errors = $this->validator->validate($places, $transitions, $previousPlaces);
        if ([] !== $errors) {
            throw new UnprocessableEntityHttpException(\json_encode(['violations' => $errors], JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(WorkflowDefinition $definition): array
    {
        return [
            'id' => $definition->getId()->toRfc4122(),
            'name' => $definition->getName(),
            'object_type_id' => $definition->getObjectTypeId()?->toRfc4122(),
            'places' => $definition->getPlaces(),
            'transitions' => $definition->getTransitions(),
            'enabled' => $definition->isEnabled(),
            'updated_at' => $definition->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
