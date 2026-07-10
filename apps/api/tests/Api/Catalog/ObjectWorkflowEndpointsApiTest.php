<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P0-05 (#2414) — procedural workflow surface (ADR-0012/ADR-0029):
 * guard-aware discovery, transition application with comment, 409 with
 * blocker context on illegal transitions, and the cursor-paginated
 * transition log fed by TransitionLoggingSubscriber (WFL-P0-04).
 */
final class ObjectWorkflowEndpointsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function discoveryListsTransitionsFromCurrentPlace(): void
    {
        $id = $this->seedProduct('WFL-EP-DISCOVERY');

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/objects/'.$id.'/workflow')->toArray();

        self::assertSame('draft', $body['current_place']);
        self::assertSame('object_editorial', $body['workflow']);

        $transitions = self::rows($body['transitions'] ?? null);
        self::assertEqualsCanonicalizing(['submit_for_review', 'publish', 'archive'], \array_column($transitions, 'name'));
        foreach ($transitions as $transition) {
            self::assertTrue($transition['enabled'], 'M0 has no guards — every topological transition is enabled');
            self::assertSame([], $transition['blockers']);
        }
    }

    #[Test]
    public function applyTransitionMovesMarkingAndLogsComment(): void
    {
        $id = $this->seedProduct('WFL-EP-APPLY');

        $client = $this->authenticatedClient();
        $body = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review', [
            'json' => ['comment' => 'Gotowe do przeglądu'],
        ])->toArray();

        self::assertSame('submit_for_review', $body['applied']);
        self::assertSame('review', $body['current_place']);

        $log = $client->request('GET', '/api/objects/'.$id.'/workflow/transitions')->toArray();
        $items = self::rows($log['items'] ?? null);
        self::assertCount(1, $items);
        self::assertSame('submit_for_review', $items[0]['transition']);
        self::assertSame('draft', $items[0]['from']);
        self::assertSame('review', $items[0]['to']);
        self::assertSame('Gotowe do przeglądu', $items[0]['comment']);
        self::assertNotNull($items[0]['actor_user_id'], 'authenticated apply must record the actor');
        self::assertNull($log['next_cursor']);
    }

    #[Test]
    public function illegalTransitionConflictsAndLeavesNoLogRow(): void
    {
        $id = $this->seedProduct('WFL-EP-409');

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/approve');
        self::assertResponseStatusCodeSame(409);

        $log = $client->request('GET', '/api/objects/'.$id.'/workflow/transitions')->toArray();
        self::assertSame([], $log['items'], 'a blocked transition must not be logged');
    }

    #[Test]
    public function unknownTransitionAndUnknownObjectAre404(): void
    {
        $id = $this->seedProduct('WFL-EP-404');

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/vanish');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/objects/'.Uuid::v7()->toRfc4122().'/workflow');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anonymousRequestsAreRejected(): void
    {
        $id = $this->seedProduct('WFL-EP-401');

        $client = static::createClient();
        $client->request('GET', '/api/objects/'.$id.'/workflow');
        self::assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/publish');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function transitionLogPaginatesWithUuidCursor(): void
    {
        $id = $this->seedProduct('WFL-EP-CURSOR');

        $client = $this->authenticatedClient();
        // Three transitions: draft -> review -> draft -> published.
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/reject');
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/publish');

        $firstPage = $client->request('GET', '/api/objects/'.$id.'/workflow/transitions?limit=2')->toArray();
        $firstItems = self::rows($firstPage['items'] ?? null);
        self::assertCount(2, $firstItems);
        self::assertSame('publish', $firstItems[0]['transition'], 'newest first');
        self::assertSame('reject', $firstItems[1]['transition']);
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $secondPage = $client->request(
            'GET',
            '/api/objects/'.$id.'/workflow/transitions?limit=2&before='.$cursor,
        )->toArray();
        $secondItems = self::rows($secondPage['items'] ?? null);
        self::assertCount(1, $secondItems);
        self::assertSame('submit_for_review', $secondItems[0]['transition']);
        self::assertNull($secondPage['next_cursor']);
    }

    #[Test]
    public function overlongCommentIsRejected(): void
    {
        $id = $this->seedProduct('WFL-EP-COMMENT');

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/publish', [
            'json' => ['comment' => \str_repeat('x', 2001)],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Narrows a decoded JSON list to typed rows — toArray() yields
     * `array<mixed>` and PHPStan max rejects offsets on mixed.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);

        $rows = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $item) {
                $typed[(string) $key] = $item;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    private function seedProduct(string $code): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $object = new CatalogObject($type, $code);
        self::getContainer()->get(CatalogObjectRepositoryInterface::class)->save($object);
        $em->flush();
        $em->clear();

        return $object->getId()->toRfc4122();
    }
}
