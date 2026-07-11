<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\PermissionRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * Pakiet C = WFL-P1-02 (#2416) + WFL-P1-03 (#2417), SEC failing-first —
 * PRD §3.8 workflow-state edit policy on every human write path: PATCH
 * (fields + attributes), bulk actions and bulk edit. Published locks
 * content edits unless edit_any_state; review needs edit_in_review;
 * archived is read-only for everyone; holders of transition.unpublish
 * (without edit_any_state) get the atomic auto-unpublish-for-edit.
 */
final class ObjectWorkflowStateEditApiTest extends CatalogApiTestCase
{
    #[Test]
    public function publishedLocksContentEditsWithoutEditAnyState(): void
    {
        $this->createUserWithRole('cm-state@demo.localhost', 'catalog_manager');
        $id = $this->seedProduct('WFL-SE-PUB', CatalogObject::STATUS_PUBLISHED);

        $client = $this->authenticatedClient('cm-state@demo.localhost');
        $response = $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['name' => ['pl' => 'Podmiana']]],
        ]);

        self::assertResponseStatusCodeSame(403);
        $body = $response->getContent(false);
        self::assertStringContainsString('workflow_state_locked', $body);
        self::assertStringContainsString('request_unpublish_available', $body);
    }

    #[Test]
    public function editAnyStateEditsPublishedInPlace(): void
    {
        $id = $this->seedProduct('WFL-SE-ANY', CatalogObject::STATUS_PUBLISHED);

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['name' => ['pl' => 'Edycja w miejscu']]],
        ]);

        self::assertResponseIsSuccessful();
        $body = $client->request('GET', '/api/products/'.$id)->toArray();
        self::assertSame(CatalogObject::STATUS_PUBLISHED, $body['status'], 'edit_any_state edits WITHOUT leaving published');
    }

    #[Test]
    public function reviewIsEditableWithEditInReview(): void
    {
        $this->createUserWithRole('cm-review@demo.localhost', 'catalog_manager');
        $id = $this->seedProduct('WFL-SE-REVIEW', CatalogObject::STATUS_REVIEW);

        $client = $this->authenticatedClient('cm-review@demo.localhost');
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['name' => ['pl' => 'Poprawka w review']]],
        ]);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function archivedIsReadOnlyEvenForEditAnyState(): void
    {
        $id = $this->seedProduct('WFL-SE-ARCH', CatalogObject::STATUS_ARCHIVED);

        $client = $this->authenticatedClient();
        $response = $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['enabled' => false],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('workflow_state_locked', $response->getContent(false));
        self::assertStringNotContainsString('request_unpublish_available', $response->getContent(false));
    }

    #[Test]
    public function autoUnpublishForEditIsAtomicAndAudited(): void
    {
        // WFL-P1-03 — custom role shaped exactly for the PRD §3.8
        // auto-unpublish window: broad edit + transition.unpublish,
        // WITHOUT edit_any_state. No seeded role sits in this window
        // (owner/admin edit in place), but the role builder can mint one.
        $this->createCustomRoleUser('unpublisher@demo.localhost', 'unpublisher', [
            'products.view', 'products.edit', 'object.view', 'object.edit', 'workflow.view',
            'workflow.transition.unpublish',
        ]);
        $id = $this->seedProduct('WFL-SE-AUTO', CatalogObject::STATUS_PUBLISHED);

        $client = $this->authenticatedClient('unpublisher@demo.localhost');
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['name' => ['pl' => 'Edycja po auto-unpublish']]],
        ]);
        self::assertResponseIsSuccessful();

        // Read state via the workflow endpoint (workflow.view) — the
        // custom role deliberately has no legacy READ grant and the
        // uppercase READ voter alias is a WFL-P6-01 audit item.
        $body = $client->request('GET', '/api/objects/'.$id.'/workflow')->toArray();
        self::assertSame(CatalogObject::STATUS_DRAFT, $body['current_place'], 'auto-unpublish must land the object in draft');

        $log = $client->request('GET', '/api/objects/'.$id.'/workflow/transitions')->toArray();
        $items = $log['items'] ?? null;
        self::assertIsArray($items);
        self::assertNotEmpty($items);
        $latest = $items[0];
        self::assertIsArray($latest);
        self::assertSame('unpublish', $latest['transition']);
        $context = $latest['context'];
        self::assertIsArray($context);
        self::assertTrue($context['auto_unpublish'] ?? null, 'transition log must carry the audit flag');
        self::assertSame('AUTO_UNPUBLISH_FOR_EDIT', $context['special_flag'] ?? null);
    }

    #[Test]
    public function bulkActionsSkipLockedRowsAndReportThem(): void
    {
        $this->createUserWithRole('cm-bulk@demo.localhost', 'catalog_manager');
        $draft = $this->seedProduct('WFL-SE-BULK-D');
        $published = $this->seedProduct('WFL-SE-BULK-P', CatalogObject::STATUS_PUBLISHED);

        $client = $this->authenticatedClient('cm-bulk@demo.localhost');
        $body = $client->request('POST', '/api/products/bulk-actions/set_attribute', [
            'json' => [
                'target_ids' => [$draft, $published],
                'payload' => ['attr' => 'name', 'value' => ['pl' => 'Bulk po stanie']],
            ],
        ])->toArray();

        self::assertSame(1, $body['locked_count'], 'published row must be dropped for catalog_manager');
        self::assertSame([$published], $body['locked_ids']);
        self::assertSame(1, $body['success_count']);
    }

    #[Test]
    public function bulkEditReportsLockedRowsAsPerIdErrors(): void
    {
        $this->createUserWithRole('cm-bulkedit@demo.localhost', 'catalog_manager');
        $published = $this->seedProduct('WFL-SE-BE-P', CatalogObject::STATUS_PUBLISHED);

        $client = $this->authenticatedClient('cm-bulkedit@demo.localhost');
        $body = $client->request('POST', '/api/products/bulk-edit', [
            'json' => [
                'operation' => 'toggle_enabled',
                'product_ids' => [$published],
                'payload' => ['enabled' => false],
            ],
        ])->toArray();

        self::assertSame(0, $body['processed']);
        self::assertSame(1, $body['errors_count']);
        $firstErrors = $body['first_errors'];
        self::assertIsArray($firstErrors);
        self::assertStringContainsString('workflow_state_locked', \json_encode($firstErrors, JSON_THROW_ON_ERROR));
    }

    private function createUserWithRole(string $email, string $roleCode): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);
        $this->persistUser($email, $tenant, $role);
    }

    /**
     * @param list<string> $permissionCodes
     */
    private function createCustomRoleUser(string $email, string $roleCode, array $permissionCodes): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $role = new Role(code: $roleCode, name: \ucfirst($roleCode), tenant: $tenant);
        $permissions = self::getContainer()->get(PermissionRepositoryInterface::class);
        foreach ($permissionCodes as $code) {
            $permission = $permissions->findByCode($code);
            \assert(null !== $permission, \sprintf('permission "%s" must be seeded', $code));
            $role->getPermissions()->add($permission);
        }
        $em->persist($role);
        $this->persistUser($email, $tenant, $role);
    }

    private function persistUser(string $email, Tenant $tenant, Role $role): void
    {
        $em = $this->em();
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
