<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\Migration\AttributeMigrationExecutor;
use App\Catalog\Application\Migration\AttributeMigrationPlan;
use App\Catalog\Application\Migration\AttributeMigrationPlanner;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use ValueError;

/**
 * UI-08.6 (#261) — `POST /api/attributes/{id}/migrate-type`.
 *
 * Body shape:
 * ```json
 * {
 *   "targetType": "select",
 *   "mappingPlan": [{"from": "stal nierdzewna", "to": "stal_nierdzewna"}],
 *   "unmappedAction": "null",
 *   "force": false,
 *   "dryRun": true,
 *   "backupSnapshot": true
 * }
 * ```
 *
 * `dryRun=true` returns the analysis only; `dryRun=false` runs the
 * migration in a transaction. Compatibility check returns 422 for
 * BLOCKED migrations and 409 when force is required but absent.
 *
 * Authorization: `attribute:write` voter — the existing AttributeVoter
 * already returns true for super_admin tenants.
 */
final class MigrateAttributeTypeController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly AttributeRepositoryInterface $attributes,
        private readonly AttributeMigrationPlanner $planner,
        private readonly AttributeMigrationExecutor $executor,
    ) {
    }

    #[Route(
        '/api/attributes/{id}/migrate-type',
        name: 'pim_attributes_migrate_type',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'modeling.attributes', action: 'add_edit')]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $attribute = $this->attributes->findById(Uuid::fromString($id));
        if (null === $attribute) {
            throw new NotFoundHttpException(\sprintf('Attribute "%s" was not found.', $id));
        }

        if ($attribute->isSystem()) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Attribute "%s" is system-managed and its type is immutable.',
                $attribute->getCode(),
            ));
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        /** @var array<string, mixed> $body */
        $plan = $this->buildPlan($body);
        $dryRun = (bool) ($body['dryRun'] ?? true);

        if ($dryRun) {
            $analysis = $this->planner->analyze($attribute, $plan);

            return new JsonResponse(['dryRun' => true, 'analysis' => $analysis->toArray()]);
        }

        $analysis = $this->executor->execute($attribute, $plan);

        return new JsonResponse(['dryRun' => false, 'analysis' => $analysis->toArray()]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildPlan(array $body): AttributeMigrationPlan
    {
        $rawTarget = $body['targetType'] ?? null;
        if (!\is_string($rawTarget) || '' === $rawTarget) {
            throw new BadRequestHttpException('targetType is required.');
        }
        try {
            $targetType = AttributeType::from($rawTarget);
        } catch (ValueError $e) {
            throw new BadRequestHttpException(\sprintf('Unknown targetType "%s".', $rawTarget), $e);
        }

        $mappings = [];
        $rawMappings = $body['mappingPlan'] ?? [];
        if (\is_array($rawMappings)) {
            foreach ($rawMappings as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $from = $row['from'] ?? null;
                $to = $row['to'] ?? null;
                if (\is_string($from) && \is_string($to)) {
                    $mappings[] = ['from' => $from, 'to' => $to];
                }
            }
        }

        $unmappedAction = $body['unmappedAction'] ?? 'null';
        if (!\is_string($unmappedAction)) {
            $unmappedAction = 'null';
        }

        return new AttributeMigrationPlan(
            targetType: $targetType,
            mappings: $mappings,
            unmappedAction: $unmappedAction,
            force: (bool) ($body['force'] ?? false),
            backupSnapshot: (bool) ($body['backupSnapshot'] ?? true),
        );
    }
}
