<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\ApiConfigurator\Domain\Entity\ApiProfile;
use App\ApiConfigurator\Domain\Enum\OutputFormat;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Shared\Application\TenantContext;
use App\Shared\Contracts\Event\AgentRunAwaitingApproval;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P8-04 (#1986) — agent lifecycle webhooks ride the EXISTING
 * producer-webhook infra (per-profile subscription, durable
 * webhook_deliveries audit row, retry off the worker): a subscribed
 * profile gets a delivery row for agent.run.awaiting_approval and
 * agent.run.completed; an unsubscribed one gets nothing.
 */
final class AgentWebhooksTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function lifecycleEventsFanOutToSubscribedProfiles(): void
    {
        $em = $this->fixture();

        $subscribed = new ApiProfile(
            code: 'hooked',
            name: 'Hooked profile',
            outputFormat: OutputFormat::JSON,
            webhookUrl: 'http://127.0.0.1:1/hook',
            webhookEvents: ['agent.run.awaiting_approval', 'agent.run.completed'],
            webhookSecret: 'whsec_test',
        );
        $em->persist($subscribed);
        $unsubscribed = new ApiProfile(
            code: 'deaf',
            name: 'Unsubscribed profile',
            outputFormat: OutputFormat::JSON,
            webhookUrl: 'http://127.0.0.1:1/other',
            webhookEvents: ['object.created.product'],
            webhookSecret: 'whsec_other',
        );
        $em->persist($unsubscribed);
        $em->flush();

        // "Plan ready" event (the loop dispatches this on materialization).
        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new AgentRunAwaitingApproval(Uuid::v7(), 'set missing prices to 100', 1800),
        );

        $conn = $em->getConnection();
        $awaiting = $conn->fetchOne(
            "SELECT COUNT(*) FROM api_webhook_deliveries WHERE event_type = 'agent.run.awaiting_approval'",
        );
        self::assertSame(1, (int) (\is_scalar($awaiting) ? $awaiting : -1), 'only the subscribed profile gets a delivery');

        // Completed event through the REAL decision path (reject).
        $batchId = Uuid::v7();
        $this->pendingChanges()->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: Uuid::v7(),
                attributeCode: 'price',
                before: null,
                after: ['value' => 100],
            ),
        ]);
        $context = self::getContainer()->get(TenantContext::class);
        $detached = $context->get();
        \assert($detached instanceof Tenant);
        $managed = $em->find(Tenant::class, $detached->getId()->toRfc4122());
        \assert($managed instanceof Tenant);
        $context->set($managed);

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'set missing prices to 100');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        $approval = self::getContainer()->get('test.agent.approval');
        \assert($approval instanceof AgentApprovalService);
        $approval->reject($run->getId());

        $completed = $conn->fetchAssociative(
            "SELECT payload::text AS payload FROM api_webhook_deliveries WHERE event_type = 'agent.run.completed'",
        );
        self::assertIsArray($completed, 'the reject decision must fan out agent.run.completed');
        self::assertIsString($completed['payload']);
        self::assertStringContainsString('rejected', $completed['payload']);
        self::assertStringContainsString($run->getId()->toRfc4122(), $completed['payload']);
    }

    private function fixture(): EntityManagerInterface
    {
        // Sync transports run the delivery handler INLINE - a real HTTP
        // failure would roll back the surrounding transaction and eat the
        // audit row. A 200-mock keeps the inline path green (prod runs it
        // off the worker with retry anyway).
        self::getContainer()->set(
            \Symfony\Contracts\HttpClient\HttpClientInterface::class,
            new \Symfony\Component\HttpClient\MockHttpClient(
                static fn (): \Symfony\Component\HttpClient\Response\MockResponse => new \Symfony\Component\HttpClient\Response\MockResponse('ok', ['http_code' => 200]),
            ),
        );

        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return $em;
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
