<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pakiet D = WFL-P2-02 (#2421) + WFL-P2-03 (#2422) — persistent
 * notification fan-out over the workflow events: submit notifies every
 * approve_reject grantee except the submitter; reject/approve notify
 * the submit author with the decision comment. Own-data surface with
 * cursor paging, unread count, read receipts and IDOR isolation.
 *
 * CI pins MESSENGER_TRANSPORT_DSN=in-memory:// — post-flush domain
 * events queue instead of running inline, so scenarios drain the
 * transport with a ReceivedStamp (lekcja ci-inmemory-messenger-drain).
 */
final class NotificationsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function submitFansOutToApproversExceptTheSubmitter(): void
    {
        $this->createUserWithRole('marketing-n@demo.localhost', 'marketing');
        $id = $this->seedProduct('WFL-N-SUBMIT');

        $marketing = $this->authenticatedClient('marketing-n@demo.localhost');
        $submit = $marketing->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review', [
            'json' => ['comment' => 'Sprawdźcie proszę'],
        ]);
        self::assertSame(200, $submit->getStatusCode());
        $this->drainAsyncTransport();

        // Admin holds workflow.approve_reject -> notified with the comment.
        $admin = $this->authenticatedClient();
        $body = $admin->request('GET', '/api/notifications')->toArray();
        self::assertSame(1, $body['unread_count']);
        $items = $body['items'];
        self::assertIsArray($items);
        $first = $items[0];
        self::assertIsArray($first);
        self::assertSame('workflow.submitted_for_review', $first['type']);
        $payload = $first['payload'];
        self::assertIsArray($payload);
        self::assertSame($id, $payload['object_id']);
        self::assertSame('Sprawdźcie proszę', $payload['comment']);

        // The submitter got nothing about their own submit.
        $mine = $marketing->request('GET', '/api/notifications')->toArray();
        self::assertSame(0, $mine['unread_count']);
        self::assertSame([], $mine['items']);
    }

    #[Test]
    public function rejectNotifiesTheSubmitAuthorWithTheComment(): void
    {
        $this->createUserWithRole('marketing-r@demo.localhost', 'marketing');
        $id = $this->seedProduct('WFL-N-REJECT');

        $marketing = $this->authenticatedClient('marketing-r@demo.localhost');
        $submit = $marketing->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        self::assertSame(200, $submit->getStatusCode());
        $this->drainAsyncTransport();

        $admin = $this->authenticatedClient();
        $reject = $admin->request('POST', '/api/objects/'.$id.'/workflow/transitions/reject', [
            'json' => ['comment' => 'Uzupełnij EAN'],
        ]);
        self::assertSame(200, $reject->getStatusCode());
        $this->drainAsyncTransport();

        $mine = $marketing->request('GET', '/api/notifications?unread=1')->toArray();
        self::assertSame(1, $mine['unread_count']);
        $items = $mine['items'];
        self::assertIsArray($items);
        $first = $items[0];
        self::assertIsArray($first);
        self::assertSame('workflow.rejected', $first['type']);
        $payload = $first['payload'];
        self::assertIsArray($payload);
        self::assertSame('Uzupełnij EAN', $payload['comment']);
    }

    #[Test]
    public function readReceiptsAndIdorIsolation(): void
    {
        $this->createUserWithRole('marketing-i@demo.localhost', 'marketing');
        $id = $this->seedProduct('WFL-N-IDOR');

        $marketing = $this->authenticatedClient('marketing-i@demo.localhost');
        $marketing->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        $this->drainAsyncTransport();

        $admin = $this->authenticatedClient();
        $body = $admin->request('GET', '/api/notifications')->toArray();
        $items = $body['items'];
        self::assertIsArray($items);
        $first = $items[0];
        self::assertIsArray($first);
        $notificationId = $first['id'];
        self::assertIsString($notificationId);

        // IDOR — the marketing principal cannot read or flip admin rows.
        // Direct status checks: the static BrowserKit assertions track the
        // MOST RECENTLY created client, and two principals are in play.
        $idor = $marketing->request('POST', '/api/notifications/'.$notificationId.'/read');
        self::assertSame(404, $idor->getStatusCode());

        $own = $admin->request('POST', '/api/notifications/'.$notificationId.'/read');
        self::assertSame(200, $own->getStatusCode());
        $after = $admin->request('GET', '/api/notifications')->toArray();
        self::assertSame(0, $after['unread_count']);

        // read-all is a no-op when everything is read already.
        $readAll = $admin->request('POST', '/api/notifications/read-all')->toArray();
        self::assertSame(0, $readAll['marked_read']);
    }

    #[Test]
    public function anonymousRequestsAreRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/notifications');
        self::assertResponseStatusCodeSame(401);
    }

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
        $transport->reset();
    }

    private function createUserWithRole(string $email, string $roleCode): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $user->addRole($role);
        $em->persist($user);
        $em->flush();
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
