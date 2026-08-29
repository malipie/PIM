<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ApprovalConflictException;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P3-04 (#1964, SEC failing-test-first) — "Cofnij tę operację":
 * rollback restores the before-state on the CANONICAL object_values
 * (a value the agent created disappears; an overwritten one gets its
 * old envelope back), the attributes_indexed projection follows canon,
 * later manual edits survive (superseded guard), and the run lands on
 * rolled_back idempotently.
 */
final class AgentRollbackTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function rollbackRemovesValuesTheAgentCreated(): void
    {
        [$em] = $this->fixture();
        $run = $this->committedRun($em, before: null, after: ['value' => 100]);

        $rolledBack = $this->approval()->rollback($run->getId());
        $this->drainAsyncTransport();

        self::assertSame(AgentRunStatus::RolledBack, $rolledBack->getStatus());

        $conn = $em->getConnection();
        $values = $conn->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(0, (int) (\is_scalar($values) ? $values : -1), 'a value the agent created must be gone after rollback');

        // Projection rebuilt from canon. The rebuild travels on the `async`
        // transport, which CI pins to `in-memory://` (quality-php.yml) — the
        // message is QUEUED there, not executed — so the drain above is what
        // makes this assertion mean anything. Until #3053 the commit-time
        // rebuild was queued and dropped the same way, so the projection was
        // never populated and "does not contain price" passed for free.
        $indexed = $conn->fetchOne('SELECT attributes_indexed FROM objects WHERE code = :c', ['c' => 'OBJ-1']);
        self::assertIsString($indexed);
        self::assertStringNotContainsString('"price"', $indexed, 'attributes_indexed must follow the restored canon');

        // Idempotent: a second rollback returns as-is.
        self::assertSame(AgentRunStatus::RolledBack, $this->approval()->rollback($run->getId())->getStatus());
    }

    #[Test]
    public function rollbackRestoresTheOverwrittenEnvelope(): void
    {
        [$em, $object, $attribute] = $this->fixture();

        // Pre-agent state: a manual value of 250.
        $this->writeManualValue($em, $object, $attribute, ['value' => 250]);

        $run = $this->committedRun($em, before: ['value' => 250], after: ['value' => 100]);

        $this->approval()->rollback($run->getId());

        $conn = $em->getConnection();
        $value = $conn->fetchOne('SELECT ov.value::text FROM object_values ov');
        self::assertIsString($value);
        self::assertStringContainsString('250', $value, 'rollback must restore the old envelope');
        self::assertStringNotContainsString('100', $value);
    }

    #[Test]
    public function laterManualEditSurvivesRollback(): void
    {
        [$em] = $this->fixture();
        $run = $this->committedRun($em, before: null, after: ['value' => 100]);

        // Kasia manually corrects the price AFTER the agent commit.
        $em->getConnection()->executeStatement(
            "UPDATE object_values SET value = '{\"value\": 999}', provenance = 'manual'",
        );

        $this->approval()->rollback($run->getId());

        $conn = $em->getConnection();
        $value = $conn->fetchOne('SELECT ov.value::text FROM object_values ov');
        self::assertIsString($value);
        self::assertStringContainsString('999', $value, 'rollback must never clobber a later manual edit');
    }

    #[Test]
    public function onlyADoneRunCanBeRolledBack(): void
    {
        [$em] = $this->fixture();
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'set prices');
        $em->persist($run);
        $em->flush();

        $this->expectException(ApprovalConflictException::class);
        $this->approval()->rollback($run->getId());
    }

    /**
     * @return array{0: EntityManagerInterface, 1: CatalogObject, 2: Attribute}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $attribute = new Attribute('price', ['en' => 'Price'], AttributeType::Number);
        $em->persist($attribute);

        $object = new CatalogObject($type, 'OBJ-1');
        $em->persist($object);
        $em->flush();

        return [$em, $object, $attribute];
    }

    /**
     * CI pins the `async` transport to `in-memory://`, so a dispatched
     * ObjectValuesChangedMessage only queues. Replay what the worker would
     * have consumed. Mirrors ContentValueCommitTest.
     */
    private function drainAsyncTransport(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        if (!$transport instanceof InMemoryTransport) {
            return;
        }
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($transport->getSent() as $envelope) {
            $bus->dispatch($envelope->getMessage(), [new ReceivedStamp('async')]);
        }
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function writeManualValue(EntityManagerInterface $em, CatalogObject $object, Attribute $attribute, array $envelope): void
    {
        $em->persist(new \App\Catalog\Domain\Entity\ObjectValue(
            object: $object,
            attribute: $attribute,
            value: $envelope,
            provenance: \App\Catalog\Domain\Provenance::Manual,
        ));
        $em->flush();
    }

    /**
     * Materialize -> approve -> commit; returns the done run.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     */
    private function committedRun(EntityManagerInterface $em, ?array $before, array $after): AgentRun
    {
        $object = $em->getRepository(CatalogObject::class)->findOneBy(['code' => 'OBJ-1']);
        \assert($object instanceof CatalogObject);

        $batchId = Uuid::v7();
        $this->pendingChanges()->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: $object->getId(),
                attributeCode: 'price',
                before: $before,
                after: $after,
            ),
        ]);

        // materialize() cleared the EM — re-attach a managed tenant.
        $context = self::getContainer()->get(TenantContext::class);
        $detached = $context->get();
        if ($detached instanceof Tenant) {
            $managed = $em->find(Tenant::class, $detached->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                $context->set($managed);
            }
        }

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'set missing prices to 100');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        $approved = $this->approval()->approve($run->getId(), Uuid::v7());
        \assert(AgentRunStatus::Done === $approved->getStatus());

        return $approved;
    }

    private function approval(): AgentApprovalService
    {
        $service = self::getContainer()->get('test.agent.approval');
        \assert($service instanceof AgentApprovalService);

        return $service;
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
