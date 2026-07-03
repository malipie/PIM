<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\AttributeType;
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
 * AGENT-P6-05 (#1978) — the attributes_indexed projection carries the
 * provenance signal: after an agent commit (sync transports run the
 * async rebuild inline) the slot holds provenance=agent and
 * provenance_meta.agent_run_id, which the admin's badge tooltip reads.
 */
final class AgentProvenanceProjectionTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function agentCommitProjectsProvenanceIntoAttributesIndexed(): void
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $em->persist(new Attribute('price', ['en' => 'Price'], AttributeType::Number));
        $object = new CatalogObject($type, 'NOPRICE-1');
        $em->persist($object);
        $em->flush();

        $batchId = Uuid::v7();
        self::getContainer()->get(PendingChangesPort::class)->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: $object->getId(),
                attributeCode: 'price',
                before: null,
                after: ['value' => 100],
            ),
        ]);

        $managed = $em->find(Tenant::class, $tenant->getId()->toRfc4122());
        \assert($managed instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($managed);

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'set missing prices to 100');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        $approval = self::getContainer()->get('test.agent.approval');
        \assert($approval instanceof AgentApprovalService);
        $approval->approve($run->getId(), Uuid::v7());

        $this->drainAsyncTransport();

        $indexed = $em->getConnection()->fetchOne(
            'SELECT attributes_indexed::text FROM objects WHERE code = :c',
            ['c' => 'NOPRICE-1'],
        );
        self::assertIsString($indexed);
        self::assertStringContainsString('"provenance": "agent"', $indexed);
        self::assertStringContainsString($run->getId()->toRfc4122(), $indexed, 'the slot must carry agent_run_id for the badge tooltip');
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * The commit dispatches ObjectValuesChangedMessage to the `async`
     * transport. Locally .env.test aliases it to sync:// (handlers run
     * in-band), but the CI phpunit job pins MESSENGER_TRANSPORT_DSN to
     * in-memory:// — queued envelopes are never consumed and the
     * projection would stay empty. Re-dispatch them with ReceivedStamp
     * so the bus runs the handlers instead of re-routing to transport.
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
}
