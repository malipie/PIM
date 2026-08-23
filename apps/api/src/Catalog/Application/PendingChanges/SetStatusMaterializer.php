<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Contracts\Command\SetStatusProposal;
use App\Catalog\Contracts\Command\SetStatusProposalPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Provenance;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Workflow\Contracts\ActingUserContextInterface;
use App\Workflow\Contracts\EditorialWorkflowProviderInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/** #2984 — guard-aware status proposals, checked again by the committer. */
final readonly class SetStatusMaterializer implements SetStatusProposalPort
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private FilterDslResolver $filterResolver,
        private EditorialWorkflowProviderInterface $workflows,
        private ActingUserContextInterface $actingUser,
        private PendingChangesPort $pendingChanges,
    ) {
    }

    public function materializeStatusTransition(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        string $transition,
        ?array $selectedIds = null,
    ): SetStatusProposal {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot materialize status changes without a current tenant.');
        }
        $objectType = $this->entityManager->getRepository(ObjectType::class)
            ->findOneBy(['code' => $objectTypeCode, 'tenant' => $tenant]);
        if (!$objectType instanceof ObjectType) {
            throw new InvalidArgumentException(\sprintf('Unknown object type "%s".', $objectTypeCode));
        }
        if ('' === trim($transition)) {
            throw new InvalidArgumentException('Transition must be a non-empty name.');
        }

        $selectorRejected = 0;
        $ids = $this->resolveObjectIds($tenant, $objectType, $filterDsl, $selectedIds, $selectorRejected);
        $drafts = [];
        $blocked = [];

        $this->actingUser->set($userId);
        try {
            foreach (array_chunk($ids, 200) as $chunk) {
                /** @var list<CatalogObject> $objects */
                $objects = $this->entityManager->createQueryBuilder()
                    ->select('co')
                    ->from(CatalogObject::class, 'co')
                    ->where('co.id IN (:ids)')
                    ->setParameter('ids', $chunk)
                    ->getQuery()
                    ->getResult();

                foreach ($objects as $object) {
                    $workflow = $this->workflows->for($object, $object->getObjectType()->getId()->toRfc4122());
                    $target = $this->transitionTarget($workflow, $transition);
                    if (null === $target || !$workflow->can($object, $transition)) {
                        $blocked[] = [
                            'object_id' => $object->getId()->toRfc4122(),
                            'object_code' => $object->getCode(),
                            'reason' => $this->blockedReason($workflow, $object, $transition, $target),
                        ];
                        continue;
                    }

                    $drafts[] = new PendingChangeDraft(
                        changeType: PendingChangeType::Status,
                        targetObjectId: $object->getId(),
                        before: ['status' => $object->getStatus()],
                        after: ['transition' => $transition, 'status' => $target],
                        meta: ['transition' => $transition],
                    );
                }
            }
        } finally {
            $this->actingUser->set(null);
        }

        if ([] !== $drafts) {
            $this->pendingChanges->materialize($batchId, Provenance::Agent->value, $drafts);
        }

        return new SetStatusProposal($batchId, \count($drafts), $selectorRejected, $blocked);
    }

    private function transitionTarget(WorkflowInterface $workflow, string $transition): ?string
    {
        foreach ($workflow->getDefinition()->getTransitions() as $candidate) {
            if ($candidate->getName() !== $transition) {
                continue;
            }
            $tos = $candidate->getTos();

            return isset($tos[0]) && \is_string($tos[0]) ? $tos[0] : null;
        }

        return null;
    }

    private function blockedReason(
        WorkflowInterface $workflow,
        CatalogObject $object,
        string $transition,
        ?string $target,
    ): string {
        if (null === $target) {
            return \sprintf('Unknown workflow transition "%s".', $transition);
        }
        $reasons = [];
        foreach ($workflow->buildTransitionBlockerList($object, $transition) as $blocker) {
            $reasons[] = $blocker->getMessage();
        }

        return [] !== $reasons
            ? implode(' ', $reasons)
            : \sprintf('Transition "%s" is not available from "%s".', $transition, $object->getStatus());
    }

    /**
     * @param array<string, mixed> $filterDsl
     * @param list<mixed>|null     $selectedIds
     *
     * @return list<string>
     */
    private function resolveObjectIds(
        Tenant $tenant,
        ObjectType $objectType,
        array $filterDsl,
        ?array $selectedIds,
        int &$selectorRejected,
    ): array {
        $params = [
            'tenant' => $tenant->getId()->toRfc4122(),
            'otid' => $objectType->getId()->toRfc4122(),
        ];
        $types = [];
        $where = '';

        if (null !== $selectedIds) {
            $clean = [];
            foreach ($selectedIds as $id) {
                if (\is_string($id) && Uuid::isValid($id)) {
                    $clean[] = $id;
                }
            }
            if ([] === $clean) {
                $selectorRejected = \count($selectedIds);

                return [];
            }
            $where = ' AND co.id IN (:ids)';
            $params['ids'] = array_values(array_unique($clean));
            $types['ids'] = ArrayParameterType::STRING;
        } elseif ([] !== $filterDsl) {
            $this->filterResolver->validate($filterDsl);
            $fragment = $this->filterResolver->toCountSql($filterDsl);
            if (null === $fragment) {
                throw new InvalidArgumentException('Filter DSL targets attributes that are not indexed yet.');
            }
            $where = ' AND ('.$fragment.')';
        }

        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT co.id FROM objects co WHERE co.tenant_id = :tenant AND co.object_type_id = :otid'.$where,
            $params,
            $types,
        );
        $ids = array_values(array_filter($rows, 'is_string'));
        if (null !== $selectedIds) {
            $selectorRejected = max(0, \count($selectedIds) - \count($ids));
        }

        return $ids;
    }
}
