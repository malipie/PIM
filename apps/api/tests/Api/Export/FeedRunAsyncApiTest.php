<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Domain\ObjectKind;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Message\RunFeedMessage;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use App\Tests\Support\InMemoryMercureHub;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * XMLF-P4-02 — async regeneration pipeline (RunFeedMessage → FeedRunHandler).
 *
 * The test env routes the `import` transport to sync://, so dispatch()
 * completes the whole pipeline inline — the 202 response already carries the
 * terminal state, and the InMemoryMercureHub captured every progress/status
 * Update the worker would have published (AUD-001: all private, all under
 * `tenant/{tid}/feeds/{feedId}/runs`).
 */
final class FeedRunAsyncApiTest extends CatalogApiTestCase
{
    #[Test]
    public function regenerateDrivesTheRunThroughTheAsyncPipelineToDone(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'async_feed');

        $response = $admin->request('POST', '/api/feeds/'.$feedId.'/regenerate');
        self::assertSame(202, $response->getStatusCode());
        $body = $response->toArray(false);

        // Sync transport completed inline — terminal state in the response.
        $runBlock = $body['run'];
        self::assertIsArray($runBlock);
        self::assertSame('done', $runBlock['status']);
        self::assertSame('manual', $runBlock['trigger']);
        $cacheBlock = $body['cache'];
        self::assertIsArray($cacheBlock);
        self::assertNotNull($cacheBlock['file_path']);
        $topic = $body['mercure_topic'];
        self::assertIsString($topic);
        self::assertStringContainsString('/feeds/'.$feedId.'/runs', $topic);

        // The worker published running + done statuses on the private topic.
        $hub = self::getContainer()->get(InMemoryMercureHub::class);
        \assert($hub instanceof InMemoryMercureHub);
        $statuses = [];
        foreach ($hub->getCapturedUpdates() as $update) {
            if ([$topic] !== $update->getTopics()) {
                continue;
            }
            self::assertTrue($update->isPrivate(), 'AUD-001: feed run updates must be private');
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            if ('status' === ($payload['event'] ?? null)) {
                $statuses[] = $payload['status'] ?? null;
            }
        }
        self::assertContains('running', $statuses);
        self::assertContains('done', $statuses);

        // Idempotency: a duplicate delivery of the same message is skipped —
        // no state transition exception, the run stays done.
        $runId = $runBlock['id'];
        self::assertIsString($runId);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new RunFeedMessage(Uuid::fromString($runId), $this->tenant()->getId()));

        $runs = self::getContainer()->get(FeedRunRepositoryInterface::class);
        $run = $runs->findById(Uuid::fromString($runId));
        self::assertNotNull($run);
        self::assertSame(FeedRunStatus::Done, $run->getStatus());
    }

    #[Test]
    public function cancelFlipsAPendingRunAndTheHandlerSkipsIt(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'cancel_feed');

        // A pending run the worker has not picked up yet (queue latency).
        $runs = self::getContainer()->get(FeedRunRepositoryInterface::class);
        self::getContainer()->get(TenantContext::class)->set($this->tenant());
        $run = new FeedRun(Uuid::fromString($feedId), FeedRunTrigger::Manual);
        $runs->save($run);
        $runId = $run->getId()->toRfc4122();

        $cancelled = $admin->request('POST', '/api/feeds/'.$feedId.'/runs/'.$runId.'/cancel');
        self::assertSame(200, $cancelled->getStatusCode());
        self::assertSame('cancelled', $cancelled->toArray(false)['status']);

        // The late delivery finds a non-pending run → skip, never a transition
        // exception, and the user's decision is not overwritten.
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new RunFeedMessage($run->getId(), $this->tenant()->getId()));

        $fresh = $runs->findById($run->getId());
        self::assertNotNull($fresh);
        self::assertSame(FeedRunStatus::Cancelled, $fresh->getStatus());

        // Cancelling a terminal run conflicts.
        $again = $admin->request('POST', '/api/feeds/'.$feedId.'/runs/'.$runId.'/cancel');
        self::assertSame(409, $again->getStatusCode());
    }

    #[Test]
    public function cancellingARunThroughAnotherFeedIs404(): void
    {
        $admin = $this->authenticatedClient();
        $feedA = $this->createFeed($admin, 'feed_a');
        $feedB = $this->createFeed($admin, 'feed_b');

        $runs = self::getContainer()->get(FeedRunRepositoryInterface::class);
        self::getContainer()->get(TenantContext::class)->set($this->tenant());
        $runOfA = new FeedRun(Uuid::fromString($feedA), FeedRunTrigger::Manual);
        $runs->save($runOfA);

        $response = $admin->request(
            'POST',
            '/api/feeds/'.$feedB.'/runs/'.$runOfA->getId()->toRfc4122().'/cancel',
        );
        self::assertSame(404, $response->getStatusCode());
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function createFeed(Client $client, string $code): string
    {
        $created = $client->request('POST', '/api/feeds', ['json' => [
            'template_kind' => 'custom',
            'code' => $code,
            'name' => 'Async feed',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'descriptor' => [
                'root' => ['element' => 'products'],
                'item' => [
                    'element' => 'product',
                    'slots' => [['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text']],
                ],
            ],
            'field_mappings' => [['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']]],
            'locale' => 'pl',
        ]]);
        self::assertSame(201, $created->getStatusCode());

        $id = $created->toArray(false)['id'];
        self::assertIsString($id);

        return $id;
    }
}
