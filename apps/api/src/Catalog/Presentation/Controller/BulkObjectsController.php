<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * ULV-05 (#987) — universal bulk-action endpoint for the ObjectListView
 * pipeline.
 *
 * `POST /api/objects/bulk` accepts a tuple of `(action, object_ids[])`
 * and applies the action across every kind of CatalogObject — including
 * custom kinds that the per-kind sugar endpoints (`/api/products/bulk-*`
 * etc.) cannot serve.
 *
 * MVP scope (minimum viable per ULV epic prioritisation):
 *   - Single action supported in this slice: `delete`. Other generic
 *     actions (change_status, assign_category, export) defer to a
 *     follow-up that pulls each from the existing rich product-side
 *     handlers ({@see BulkActionsController}) and generalises them.
 *   - Hard limit 1000 object_ids per request — exceeded → 400 / Problem
 *     Details. Async batching via Symfony Messenger lands in the same
 *     follow-up.
 *   - Permission re-check is server-side via the generic ULV-04a
 *     `object.{action}` PRD code — every object_id in the request must
 *     resolve to the same tenant and the caller must hold the verb. Any
 *     object missing the grant → entire request fails (no partial
 *     execution).
 *   - Audit log entry is deferred to the follow-up — the existing
 *     `AuditLog` entity is `Identity_Internals` and Catalog cannot
 *     consume it without a dedicated `Identity_Contracts` interface
 *     that does not yet exist. The dh-auditor bundle still records
 *     domain entity mutations on the `objects` table via `objects_audit`,
 *     so the per-row deletion trail is preserved.
 */
final class BulkObjectsController
{
    private const int HARD_CAP = 1000;
    private const array SUPPORTED_ACTIONS = ['delete'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly CatalogObjectRepositoryInterface $catalogObjects,
    ) {
    }

    #[Route('/api/objects/bulk', name: 'pim_objects_bulk', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // #2881 — the per-kind delete codes are safe alternatives HERE and
    // nowhere else: the loop below re-checks `is_granted('DELETE', $object)`
    // per object, so holding products.delete opens the endpoint but not a
    // single category row. Pinned by BulkDeleteKindBoundaryTest — without
    // that re-check this would be the coarse-bulk-gate escalation.
    #[RequiresPermission(module: 'object', action: 'delete', anyOf: [
        'object.delete',
        'products.delete',
        'categories.delete',
        'multimedia.delete',
    ])]
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('No tenant context.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $action = $body['action'] ?? null;
        if (!\is_string($action) || !\in_array($action, self::SUPPORTED_ACTIONS, true)) {
            throw new BadRequestHttpException(
                \sprintf('Unsupported action; expected one of: %s.', implode(', ', self::SUPPORTED_ACTIONS)),
            );
        }

        $objectIds = $body['object_ids'] ?? null;
        if (!\is_array($objectIds) || [] === $objectIds) {
            throw new BadRequestHttpException('object_ids must be a non-empty array of UUIDs.');
        }

        if (\count($objectIds) > self::HARD_CAP) {
            throw new BadRequestHttpException(\sprintf('object_ids exceeds %d hard cap.', self::HARD_CAP));
        }

        $normalised = [];
        foreach ($objectIds as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new BadRequestHttpException('object_ids contains a non-UUID value.');
            }
            $normalised[] = $id;
        }
        $objectIds = array_values(array_unique($normalised));

        // `IS_AUTHENTICATED_FULLY` above guarantees a logged-in principal;
        // we do not need the User entity here, only the per-object voter
        // chain that reads it through the token.

        // Per-object permission re-check — every CatalogObject must pass
        // the existing tenant-aware DELETE voter chain (CatalogObjectVoter
        // + per-kind sibling voters). The generic ObjectScopedVoter from
        // ULV-04a layers on top once both PRs land; this endpoint relies
        // on the legacy gate today so it ships without ULV-04a coupling.
        $affected = [];
        /** @var array<string, ObjectType> $seenTypes */
        $seenTypes = [];

        // Only `delete` is supported in this MVP slice (guarded above); the
        // mapping pre-exists for the follow-up that adds change_status etc.
        $voterAttribute = 'DELETE';

        foreach ($objectIds as $rawId) {
            $catalogObject = $this->catalogObjects->findById(Uuid::fromString($rawId));
            if (null === $catalogObject) {
                continue; // Already gone — nothing to delete.
            }

            $objectTenant = $catalogObject->getTenant();
            if (null === $objectTenant || $objectTenant->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                throw new AccessDeniedHttpException(\sprintf('Object %s is outside the current tenant.', $rawId));
            }

            if (!$this->security->isGranted($voterAttribute, $catalogObject)) {
                throw new AccessDeniedHttpException(
                    \sprintf('Missing %s permission for object %s.', $action, $rawId),
                );
            }

            $objectType = $catalogObject->getObjectType();
            $seenTypes[$objectType->getId()->toRfc4122()] = $objectType;
            $affected[] = $catalogObject;
        }

        if ([] !== $affected) {
            foreach ($affected as $row) {
                $this->em->remove($row);
            }
            $this->em->flush();
        }

        return new JsonResponse(
            [
                'action' => $action,
                'requested' => \count($objectIds),
                'affected' => \count($affected),
                'object_types' => array_keys($seenTypes),
            ],
            Response::HTTP_OK,
        );
    }
}
