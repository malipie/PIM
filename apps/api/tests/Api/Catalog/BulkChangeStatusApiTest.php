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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * WFL-P1-05 (#2419, SEC) — bulk change_status through the state machine.
 * Input is a transition name; topology + RBAC guards + completeness gate
 * are evaluated per object via can(), so no bulk path exceeds the
 * single-item surface (lekcja bulk-endpoint-permission-escalation).
 * Blocked rows fail individually (skipped + warning BulkLog) without
 * stopping the batch.
 */
final class BulkChangeStatusApiTest extends CatalogApiTestCase
{
    #[Test]
    public function bulkSubmitMovesEveryDraftAndLogsTransitions(): void
    {
        $ids = [
            $this->seedProduct('WFL-BCS-1'),
            $this->seedProduct('WFL-BCS-2'),
            $this->seedProduct('WFL-BCS-3'),
        ];

        $client = $this->authenticatedClient();
        $body = $client->request('POST', '/api/products/bulk-actions/change_status', [
            'json' => [
                'target_ids' => $ids,
                'payload' => ['transition' => 'submit_for_review', 'comment' => 'Zbiorcze zgłoszenie'],
            ],
        ])->toArray();

        self::assertSame(3, $body['success_count']);
        self::assertSame(0, $body['skipped_count']);
        self::assertSame(0, $body['error_count']);

        $state = $client->request('GET', '/api/objects/'.$ids[0].'/workflow')->toArray();
        self::assertSame('review', $state['current_place']);

        $log = $client->request('GET', '/api/objects/'.$ids[0].'/workflow/transitions')->toArray();
        $items = $log['items'] ?? null;
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('submit_for_review', $first['transition']);
        self::assertSame('Zbiorcze zgłoszenie', $first['comment']);
        $context = $first['context'];
        self::assertIsArray($context);
        self::assertTrue($context['bulk'] ?? null, 'bulk applies must be flagged in the transition log');
    }

    #[Test]
    public function mixedStatesFailIndividuallyWithoutStoppingTheBatch(): void
    {
        $draft = $this->seedProduct('WFL-BCS-MIX-D');
        $published = $this->seedProduct('WFL-BCS-MIX-P', CatalogObject::STATUS_PUBLISHED);

        $client = $this->authenticatedClient();
        $body = $client->request('POST', '/api/products/bulk-actions/change_status', [
            'json' => [
                'target_ids' => [$draft, $published],
                'payload' => ['transition' => 'submit_for_review'],
            ],
        ])->toArray();

        self::assertSame(1, $body['success_count'], 'draft row transitions');
        self::assertSame(1, $body['skipped_count'], 'published row is topologically blocked, not silently written');

        $state = $client->request('GET', '/api/objects/'.$published.'/workflow')->toArray();
        self::assertSame('published', $state['current_place'], 'blocked row must keep its state');
    }

    #[Test]
    public function marketingCannotBulkPublishPastTheGuards(): void
    {
        $this->createUserWithRole('marketing-bulk@demo.localhost', 'marketing');
        $draft = $this->seedProduct('WFL-BCS-ESC');

        $client = $this->authenticatedClient('marketing-bulk@demo.localhost');
        $body = $client->request('POST', '/api/products/bulk-actions/change_status', [
            'json' => [
                'target_ids' => [$draft],
                'payload' => ['transition' => 'publish'],
            ],
        ])->toArray();

        self::assertSame(0, $body['success_count'], 'guards must block bulk publish for marketing');
        self::assertSame(1, $body['skipped_count']);

        $state = $this->authenticatedClient()->request('GET', '/api/objects/'.$draft.'/workflow')->toArray();
        self::assertSame('draft', $state['current_place']);
    }

    #[Test]
    public function unknownTransitionIsRejected(): void
    {
        $draft = $this->seedProduct('WFL-BCS-400');

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/products/bulk-actions/change_status', [
            'json' => [
                'target_ids' => [$draft],
                'payload' => ['transition' => 'teleport'],
            ],
        ]);
        self::assertResponseStatusCodeSame(400);
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

    private function seedProduct(string $code, string $status = CatalogObject::STATUS_DRAFT): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $object = new CatalogObject($type, $code);
        if (CatalogObject::STATUS_DRAFT !== $status) {
            $object->forceStatus($status);
        }
        self::getContainer()->get(CatalogObjectRepositoryInterface::class)->save($object);
        $em->flush();
        $em->clear();

        return $object->getId()->toRfc4122();
    }
}
