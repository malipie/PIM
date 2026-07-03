<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Catalog\Contracts\Command\AssignCategoriesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P3-05 (#1965) — mass categorisation through the approval gate:
 * materialization leaves the junction untouched, approve commits
 * through the existing bulk category handlers (undo-log included),
 * rollback replays the previous assignment list, and a non-category
 * id is rejected instead of materialized.
 */
final class AssignCategoriesFlowTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function materializationLeavesTheJunctionUntouched(): void
    {
        [$em, , $category] = $this->fixture();

        $proposal = $this->port()->materializeCategoryAssignments(
            batchId: $batchId = Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            categoryIds: [$category->getId()->toRfc4122()],
            operation: 'add',
        );

        self::assertSame(1, $proposal->affectedObjects);
        self::assertSame([], $proposal->rejected);

        $junction = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_categories');
        self::assertSame(0, (int) (\is_scalar($junction) ? $junction : -1), 'materialization must not touch the junction');

        $rows = $this->pendingChanges()->listBatch($batchId);
        self::assertCount(1, $rows);
        self::assertSame(PendingChangeType::Category, $rows[0]->changeType);
        self::assertSame(PendingChangeStatus::Pending, $rows[0]->status);
        self::assertSame(['category_ids' => []], $rows[0]->before);
        self::assertIsArray($rows[0]->after);
        self::assertSame('add', $rows[0]->after['operation'] ?? null);
    }

    #[Test]
    public function approveCommitsTheJunctionAndRollbackRestoresIt(): void
    {
        [$em, $product, $category] = $this->fixture();

        $batchId = Uuid::v7();
        $this->port()->materializeCategoryAssignments(
            batchId: $batchId,
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            categoryIds: [$category->getId()->toRfc4122()],
            operation: 'add',
        );

        $run = $this->awaitingRun($em, $batchId);
        $approved = $this->approval()->approve($run->getId(), Uuid::v7());

        self::assertSame(AgentRunStatus::Done, $approved->getStatus());

        $conn = $em->getConnection();
        $junction = $conn->fetchOne(
            'SELECT COUNT(*) FROM object_categories WHERE object_id = :o AND category_id = :c',
            ['o' => $product->getId()->toRfc4122(), 'c' => $category->getId()->toRfc4122()],
        );
        self::assertSame(1, (int) (\is_scalar($junction) ? $junction : -1), 'approve must write the junction');

        $bulkOperationId = $approved->getBulkOperationId();
        self::assertInstanceOf(Uuid::class, $bulkOperationId);
        $actionType = $conn->fetchOne('SELECT action_type FROM bulk_sessions WHERE id = :s', ['s' => $bulkOperationId->toRfc4122()]);
        self::assertSame('add_category', $actionType);

        // Rollback replays the previous (empty) assignment list.
        $rolledBack = $this->approval()->rollback($run->getId());
        self::assertSame(AgentRunStatus::RolledBack, $rolledBack->getStatus());
        $junction = $conn->fetchOne('SELECT COUNT(*) FROM object_categories');
        self::assertSame(0, (int) (\is_scalar($junction) ? $junction : -1), 'rollback must restore the previous assignments');
    }

    #[Test]
    public function nonCategoryIdIsRejectedNotMaterialized(): void
    {
        [, $product] = $this->fixture();

        $proposal = $this->port()->materializeCategoryAssignments(
            batchId: Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            categoryIds: [$product->getId()->toRfc4122(), Uuid::v7()->toRfc4122()],
            operation: 'add',
        );

        self::assertSame(0, $proposal->affectedObjects);
        self::assertCount(2, $proposal->rejected);
        self::assertSame('Object is not a category.', $proposal->rejected[0]['reason']);
        self::assertSame('Unknown category.', $proposal->rejected[1]['reason']);
    }

    #[Test]
    public function selectionScopeTargetsOnlyTheSelectedObjects(): void
    {
        // #2153 — "add category Botki for the 2 selected products" must
        // touch only those, not every product in the view.
        [$em, $product, $category] = $this->fixture();

        $productType = $product->getObjectType();
        $other = new CatalogObject($productType, 'FESTO-2');
        $em->persist($other);
        $em->flush();

        $batchId = Uuid::v7();
        $proposal = $this->port()->materializeCategoryAssignments(
            batchId: $batchId,
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            categoryIds: [$category->getId()->toRfc4122()],
            operation: 'add',
            selectedIds: [$product->getId()->toRfc4122()],
        );

        self::assertSame(1, $proposal->affectedObjects, 'only the selected product, not FESTO-2');

        $rows = $this->pendingChanges()->listBatch($batchId);
        self::assertCount(1, $rows);
        self::assertTrue($product->getId()->equals($rows[0]->targetObjectId ?? Uuid::v4()));
    }

    /**
     * @return array{0: EntityManagerInterface, 1: CatalogObject, 2: CatalogObject}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $productType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $categoryType = new ObjectType('category', ObjectKind::Category, ['en' => 'Category']);
        $em->persist($productType);
        $em->persist($categoryType);

        $product = new CatalogObject($productType, 'FESTO-1');
        $category = new CatalogObject($categoryType, 'CAT-AUTOMATION');
        $em->persist($product);
        $em->persist($category);
        $em->flush();

        return [$em, $product, $category];
    }

    private function awaitingRun(EntityManagerInterface $em, Uuid $batchId): AgentRun
    {
        // materialize() clears the EM internally — re-attach a managed tenant.
        $context = self::getContainer()->get(TenantContext::class);
        $detached = $context->get();
        if ($detached instanceof Tenant) {
            $managed = $em->find(Tenant::class, $detached->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                $context->set($managed);
            }
        }

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'assign Festo products to Automation');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        return $run;
    }

    private function approval(): AgentApprovalService
    {
        $service = self::getContainer()->get('test.agent.approval');
        \assert($service instanceof AgentApprovalService);

        return $service;
    }

    private function port(): AssignCategoriesPort
    {
        return self::getContainer()->get(AssignCategoriesPort::class);
    }

    private function pendingChanges(): PendingChangesPort
    {
        return self::getContainer()->get(PendingChangesPort::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
