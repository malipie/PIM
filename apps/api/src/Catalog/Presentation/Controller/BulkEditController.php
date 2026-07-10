<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Entity\BulkEditJob;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Policy\WorkflowStateEditPolicyInterface;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * UI-02.3 (#293) — bulk edit endpoint + job status read.
 *
 * MVP scope: synchronous execution inside the request, capped at 5000
 * `product_ids[]` per call. Two operations supported in this slice:
 *   - `toggle_enabled` — payload `{enabled: bool}`.
 *   - `set_attribute_value` — payload `{attribute_code: string, value: scalar}`.
 *
 * Per-row failures are collected on the `BulkEditJob` row (first 100
 * `firstErrors` plus a counter) instead of failing the whole batch.
 *
 * **Out of MVP slice (Faza 1+):** async dispatch via Messenger (the
 * `BulkEditJob` row already supports it — runner just flips status to
 * `running` and persists), Mercure progress events, `add_to_category`
 * + `remove_from_category` + `delete` operations, publish-to-channels.
 */
final class BulkEditController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const int MAX_IDS = 5000;

    private const array SUPPORTED_OPERATIONS = ['toggle_enabled', 'set_attribute_value'];

    public function __construct(
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly WorkflowStateEditPolicyInterface $statePolicy,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly BulkOperationLock $bulkLock,
    ) {
    }

    /**
     * Priority `200` to keep this above the API Platform `/api/products/{id}`
     * collection route.
     */
    #[Route(
        '/api/products/bulk-edit',
        name: 'pim_products_bulk_edit',
        methods: ['POST'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'bulk_operations')]
    public function bulkEdit(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('No tenant context.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $operation = $body['operation'] ?? null;
        if (!\is_string($operation) || !\in_array($operation, self::SUPPORTED_OPERATIONS, true)) {
            throw new BadRequestHttpException(\sprintf(
                'operation must be one of: %s.',
                implode(', ', self::SUPPORTED_OPERATIONS),
            ));
        }

        $rawIds = $body['product_ids'] ?? null;
        if (!\is_array($rawIds) || [] === $rawIds) {
            throw new BadRequestHttpException('product_ids must be a non-empty array of UUID strings.');
        }
        if (\count($rawIds) > self::MAX_IDS) {
            throw new BadRequestHttpException(\sprintf('product_ids capped at %d entries per call.', self::MAX_IDS));
        }

        $payload = $body['payload'] ?? [];
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('payload must be an object.');
        }
        /** @var array<string, mixed> $payload */

        // IMP2-2.9 (#1485) — coordinate with imports / other bulk ops under the
        // per-tenant lock. A collision is a 409 (RFC 7807); released in finally so
        // an exception mid-batch never leaks the lock.
        $lock = $this->bulkLock->acquire($tenant);
        if (null === $lock) {
            throw new ConflictHttpException(
                'Inna operacja masowa trwa dla tego tenanta — spróbuj ponownie po jej zakończeniu.',
            );
        }

        try {
            $job = new BulkEditJob(
                operation: $operation,
                payload: $payload,
                total: \count($rawIds),
            );
            $this->em->persist($job);
            $this->em->flush();

            $job->markRunning();

            $processed = 0;
            foreach ($rawIds as $rawId) {
                try {
                    if (!\is_string($rawId)) {
                        throw new BadRequestHttpException('product_ids entries must be UUID strings.');
                    }
                    $uuid = Uuid::fromString($rawId);
                    $object = $this->objects->findById($uuid);
                    if (!$object instanceof CatalogObject || ObjectKind::Product !== $object->getKind()) {
                        throw new NotFoundHttpException(\sprintf('Product %s not found.', $rawId));
                    }

                    // WFL-P1-02 (#2416) — PRD §3.8 state gate per row; the
                    // catch below records it as a per-id error, so locked
                    // rows are reported instead of silently written. No
                    // auto-unpublish on bulk paths by design (a mass
                    // unpublish-for-edit would be a surprising side effect).
                    $state = $object->getStatus();
                    if (!$this->statePolicy->canEditInState($state)) {
                        throw new AccessDeniedHttpException(\sprintf(
                            'workflow_state_locked: object is "%s" — content edits are blocked by the workflow state policy.',
                            $state,
                        ));
                    }

                    $this->applyOperation($operation, $object, $payload);

                    ++$processed;
                } catch (Throwable $e) {
                    $job->recordError(\is_string($rawId) ? $rawId : '<invalid>', $e->getMessage());
                }
            }

            $job->recordProgress($processed);
            $job->markCompleted();
            $this->em->flush();

            return new JsonResponse(
                $this->serializeJob($job),
                Response::HTTP_ACCEPTED,
            );
        } finally {
            $lock->release();
        }
    }

    #[Route(
        '/api/bulk-edit-jobs/{id}',
        name: 'pim_bulk_edit_jobs_show',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'bulk_operations')]
    public function show(string $id): JsonResponse
    {
        $job = $this->em->getRepository(BulkEditJob::class)->find(Uuid::fromString($id));
        if (!$job instanceof BulkEditJob) {
            throw new NotFoundHttpException(\sprintf('Bulk edit job %s not found.', $id));
        }

        return new JsonResponse($this->serializeJob($job));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyOperation(string $operation, CatalogObject $object, array $payload): void
    {
        switch ($operation) {
            case 'toggle_enabled':
                $enabled = $payload['enabled'] ?? null;
                if (!\is_bool($enabled)) {
                    throw new BadRequestHttpException('payload.enabled must be a boolean.');
                }
                $object->changeEnabled($enabled);
                break;

            case 'set_attribute_value':
                $code = $payload['attribute_code'] ?? null;
                $value = $payload['value'] ?? null;
                if (!\is_string($code) || '' === $code) {
                    throw new BadRequestHttpException('payload.attribute_code is required.');
                }
                $current = $object->getAttributesIndexed();
                $current[$code] = $value;
                $object->updateAttributeIndex($current);
                break;
        }

        $this->objects->save($object);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeJob(BulkEditJob $job): array
    {
        return [
            'id' => $job->getId()->toRfc4122(),
            'operation' => $job->getOperation(),
            'status' => $job->getStatus(),
            'total' => $job->getTotal(),
            'processed' => $job->getProcessed(),
            'errors_count' => $job->getErrorsCount(),
            'first_errors' => $job->getFirstErrors(),
            'payload' => $job->getPayload(),
            'created_at' => $job->getCreatedAt()->format(DateTimeInterface::ATOM),
            'completed_at' => $job->getCompletedAt()?->format(DateTimeInterface::ATOM),
        ];
    }
}
