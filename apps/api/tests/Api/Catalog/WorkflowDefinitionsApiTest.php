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
use App\Workflow\Application\EditorialWorkflowProvider;
use App\Workflow\Domain\Entity\WorkflowDefinition;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Pakiet G = WFL-P5-01 (#2431) + WFL-P5-02 (#2432) — tenant workflow
 * definitions: CRUD gated manage_definitions, write-edge validation
 * (422 with field-scoped violations) and the runtime provider that
 * builds the machine from the DB with per-transition metadata
 * (permission guard + comment_required + completeness gate) while the
 * flag-off path stays byte-identical to the static YAML.
 */
final class WorkflowDefinitionsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function crudIsGatedByManageDefinitions(): void
    {
        $this->createUserWithRole('marketing-d@demo.localhost', 'marketing');
        $marketing = $this->authenticatedClient('marketing-d@demo.localhost');
        $denied = $marketing->request('GET', '/api/workflow/definitions');
        self::assertSame(403, $denied->getStatusCode(), 'marketing has no manage_definitions');

        $admin = $this->authenticatedClient();
        $created = $admin->request('POST', '/api/workflow/definitions', [
            'json' => $this->validShape('Prosty łańcuch'),
        ])->toArray();
        self::assertFalse($created['enabled'], 'definitions start disabled');
        $id = $created['id'];
        self::assertIsString($id);

        $enabled = $admin->request('POST', '/api/workflow/definitions/'.$id.'/enable')->toArray();
        self::assertTrue($enabled['enabled']);

        $list = $admin->request('GET', '/api/workflow/definitions')->toArray();
        $items = $list['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
    }

    #[Test]
    public function malformedShapesReturnFieldScopedViolations(): void
    {
        $admin = $this->authenticatedClient();

        // Unreachable place + unknown permission code.
        $response = $admin->request('POST', '/api/workflow/definitions', [
            'json' => [
                'name' => 'Zepsuty',
                'places' => [['name' => 'draft'], ['name' => 'limbo']],
                'transitions' => [
                    ['name' => 'go', 'from' => 'draft', 'to' => 'draft', 'permission' => 'workflow.nonexistent'],
                ],
            ],
        ]);
        self::assertSame(422, $response->getStatusCode());
        $detail = $response->getContent(false);
        self::assertStringContainsString('unreachable', $detail);
        self::assertStringContainsString('workflow.nonexistent', $detail);
    }

    #[Test]
    public function reviewerRoleRoundtripsAndUnknownReviewersAreRejected(): void
    {
        // #2513 — a definition carries one configurable approver.
        $admin = $this->authenticatedClient();

        // Role reviewer that exists in the tenant → 201 + serialized back.
        $shape = $this->validShape('Z akceptantem');
        $shape['reviewer'] = ['role_code' => 'approver'];
        $created = $admin->request('POST', '/api/workflow/definitions', ['json' => $shape])->toArray();
        self::assertSame(['role_code' => 'approver'], $created['reviewer']);
        $id = $created['id'];
        self::assertIsString($id);
        $fetched = $admin->request('GET', '/api/workflow/definitions/'.$id)->toArray();
        self::assertSame(['role_code' => 'approver'], $fetched['reviewer']);

        // Unknown role → 422.
        $shape['reviewer'] = ['role_code' => 'no_such_role'];
        $unknown = $admin->request('POST', '/api/workflow/definitions', ['json' => $shape]);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertStringContainsString('reviewer', $unknown->getContent(false));

        // Both role and user (XOR violation) → 422.
        $shape['reviewer'] = ['role_code' => 'approver', 'user_id' => '019f2bb4-0000-7000-8000-000000000000'];
        $xor = $admin->request('POST', '/api/workflow/definitions', ['json' => $shape]);
        self::assertSame(422, $xor->getStatusCode());

        // A user id that is not in this tenant → 422.
        $shape['reviewer'] = ['user_id' => '019f2bb4-0000-7000-8000-000000000000'];
        $foreignUser = $admin->request('POST', '/api/workflow/definitions', ['json' => $shape]);
        self::assertSame(422, $foreignUser->getStatusCode());
    }

    #[Test]
    public function providerResolvesReviewerFromDefinition(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $object = $this->seedProduct('WFL-DEF-REVIEWER');
        $productTypeId = $object->getObjectType()->getId()->toRfc4122();

        $definition = new WorkflowDefinition(
            'Z akceptantem',
            [['name' => 'draft'], ['name' => 'published']],
            [['name' => 'publish_direct', 'from' => 'draft', 'to' => 'published']],
            $object->getObjectType()->getId(),
            ['role_code' => 'catalog_manager'],
        );
        $definition->assignTenant($tenant);
        $definition->setEnabled(true);
        $em->persist($definition);
        $em->flush();

        $provider = new EditorialWorkflowProvider(
            self::getContainer()->get(Registry::class)->get($object, 'object_editorial'),
            $em,
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(EventDispatcherInterface::class),
            customDefinitionsEnabled: true,
        );
        $assignee = $provider->reviewerFor($productTypeId);
        self::assertNotNull($assignee);
        self::assertSame('catalog_manager', $assignee->roleCode);
        self::assertNull($assignee->userId);

        // Flag off → no configured reviewer (caller falls back to approver).
        $offProvider = new EditorialWorkflowProvider(
            self::getContainer()->get(Registry::class)->get($object, 'object_editorial'),
            $em,
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(EventDispatcherInterface::class),
            customDefinitionsEnabled: false,
        );
        self::assertNull($offProvider->reviewerFor($productTypeId));
    }

    #[Test]
    public function providerBuildsTheDbMachineWithMetadataWhenFlagIsOn(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        // Custom chain with a translation stage; approve requires the
        // dedicated permission and a comment.
        $definition = new WorkflowDefinition('Z tłumaczeniem', [
            ['name' => 'draft'],
            ['name' => 'translation'],
            ['name' => 'published'],
        ], [
            ['name' => 'to_translation', 'from' => 'draft', 'to' => 'translation'],
            ['name' => 'approve_translation', 'from' => 'translation', 'to' => 'published',
                'permission' => 'workflow.approve_reject', 'comment_required' => true],
        ]);
        $definition->assignTenant($tenant);
        $definition->setEnabled(true);
        $em->persist($definition);
        $em->flush();

        $object = $this->seedProduct('WFL-DEF-CUSTOM');

        // Flag ON provider constructed directly — the container-compiled
        // one is flag-off (env default); same collaborators otherwise.
        $provider = new EditorialWorkflowProvider(
            self::getContainer()->get(Registry::class)->get($object, 'object_editorial'),
            $em,
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(EventDispatcherInterface::class),
            customDefinitionsEnabled: true,
        );

        $workflow = $provider->for($object, $object->getObjectType()->getId()->toRfc4122());
        self::assertTrue($workflow->can($object, 'to_translation'), 'custom transition works from draft');
        self::assertFalse($workflow->can($object, 'submit_for_review'), 'static transitions are gone under the custom machine');

        $workflow->apply($object, 'to_translation');
        self::assertSame('translation', $object->getStatus());

        // The admin principal holds approve_reject — the metadata guard
        // admits the transition (no security token here => system path,
        // but metadata plumbing is what this pins via the blocker list).
        self::assertTrue($workflow->can($object, 'approve_translation'));

        // Flag OFF provider ignores the definition entirely.
        $offProvider = new EditorialWorkflowProvider(
            self::getContainer()->get(Registry::class)->get($object, 'object_editorial'),
            $em,
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(EventDispatcherInterface::class),
            customDefinitionsEnabled: false,
        );
        $static = $offProvider->for($object, null);
        self::assertInstanceOf(WorkflowInterface::class, $static);
        self::assertNotNull($static->getDefinition()->getPlaces()['review'] ?? null, 'flag off = static YAML places');
    }

    /**
     * @return array<string, mixed>
     */
    private function validShape(string $name): array
    {
        return [
            'name' => $name,
            'places' => [['name' => 'draft'], ['name' => 'published']],
            'transitions' => [
                ['name' => 'publish_direct', 'from' => 'draft', 'to' => 'published', 'permission' => 'workflow.approve_reject'],
                ['name' => 'retract', 'from' => 'published', 'to' => 'draft'],
            ],
        ];
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

    private function seedProduct(string $code): CatalogObject
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

        return $object;
    }
}
