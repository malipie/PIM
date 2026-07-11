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
 * WFL-P1-01 (#2415, SEC) — the RBAC guard map on `object_editorial`
 * transitions, asserted per PRD-PIM-rbac §3.8 role matrix. Written
 * failing-first against the unguarded M0 machine: every deny case in
 * this file returned 200 before TransitionPermissionGuard attached.
 *
 * Map under test: submit_for_review -> products.edit · publish/approve/
 * reject/archive/restore -> workflow.approve_reject · unpublish ->
 * workflow.transition.unpublish.
 */
final class ObjectWorkflowGuardsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function marketingCanSubmitButCannotPublish(): void
    {
        $this->createUserWithRole('marketing@demo.localhost', 'marketing');
        $id = $this->seedProduct('WFL-G-MARKETING');

        $client = $this->authenticatedClient('marketing@demo.localhost');

        $response = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/publish');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString(
            'workflow.approve_reject',
            $response->getContent(false),
            '409 must name the missing permission',
        );

        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function discoveryDisablesBlockedTransitionsPerRole(): void
    {
        $this->createUserWithRole('marketing2@demo.localhost', 'marketing');
        $id = $this->seedProduct('WFL-G-DISCOVERY');

        $body = $this->authenticatedClient('marketing2@demo.localhost')
            ->request('GET', '/api/objects/'.$id.'/workflow')->toArray();

        $byName = [];
        foreach (self::rows($body['transitions'] ?? null) as $transition) {
            $name = $transition['name'];
            self::assertIsString($name);
            $byName[$name] = $transition;
        }

        self::assertTrue($byName['submit_for_review']['enabled']);
        self::assertFalse($byName['publish']['enabled']);
        $blockers = self::rows($byName['publish']['blockers']);
        self::assertSame('workflow.approve_reject', $blockers[0]['code']);

        // Admin sees everything from draft enabled.
        $adminBody = $this->authenticatedClient()
            ->request('GET', '/api/objects/'.$id.'/workflow')->toArray();
        foreach (self::rows($adminBody['transitions'] ?? null) as $transition) {
            $name = $transition['name'];
            self::assertIsString($name);
            self::assertTrue($transition['enabled'], \sprintf('admin should pass guard for "%s"', $name));
        }
    }

    #[Test]
    public function approverApprovesButCannotSubmit(): void
    {
        $this->createUserWithRole('approver@demo.localhost', 'approver');
        $inReview = $this->seedProduct('WFL-G-APPROVE', CatalogObject::STATUS_REVIEW);
        $draft = $this->seedProduct('WFL-G-NOSUBMIT');

        $client = $this->authenticatedClient('approver@demo.localhost');

        $client->request('POST', '/api/objects/'.$draft.'/workflow/transitions/submit_for_review');
        self::assertResponseStatusCodeSame(409, 'approver has no products.edit — submit is blocked');

        $client->request('POST', '/api/objects/'.$inReview.'/workflow/transitions/approve', [
            'json' => ['comment' => 'Zatwierdzam'],
        ]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function unpublishRequiresDedicatedPermission(): void
    {
        $this->createUserWithRole('cm@demo.localhost', 'catalog_manager');
        $this->createUserWithRole('approver2@demo.localhost', 'approver');
        $id = $this->seedProduct('WFL-G-UNPUBLISH', CatalogObject::STATUS_PUBLISHED);

        $client = $this->authenticatedClient('cm@demo.localhost');
        $response = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/unpublish');
        self::assertResponseStatusCodeSame(409, 'catalog_manager approves but must not auto-unpublish (PRD §3.8)');
        self::assertStringContainsString('workflow.transition.unpublish', $response->getContent(false));

        $this->authenticatedClient('approver2@demo.localhost')
            ->request('POST', '/api/objects/'.$id.'/workflow/transitions/unpublish');
        self::assertResponseIsSuccessful();
    }

    /**
     * Layering note: the legacy PATCH surface is gated by
     * `is_granted('UPDATE', object)` (CatalogObjectVoter, legacy
     * `object.write`) BEFORE the workflow guard can run — a marketing
     * user is stopped at 403 and never reaches the state machine. That
     * is defence in depth, not a bypass: guard parity for the PATCH
     * path itself is pinned by the acting-user kernel test in
     * tests/Integration/Workflow (getEnabledTransitions honours guards
     * for whoever DOES pass the endpoint gate).
     */
    #[Test]
    public function patchStatusSurfaceIsGatedBeforeTheGuards(): void
    {
        $this->createUserWithRole('marketing3@demo.localhost', 'marketing');
        $id = $this->seedProduct('WFL-G-PATCH');

        $client = $this->authenticatedClient('marketing3@demo.localhost');
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['status' => CatalogObject::STATUS_PUBLISHED],
        ]);
        self::assertResponseStatusCodeSame(403, 'endpoint RBAC fires before the workflow guard');
    }

    /**
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
