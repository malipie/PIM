<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * #2881 — the write half of the poly-kind surface, the counterpart to
 * {@see ObjectCollectionReadAccessTest}.
 *
 * `POST /api/objects` is where the admin actually creates: the product
 * detail form posts here rather than to `/api/products`, and multimedia has
 * no create sugar path at all. It was gated by a kind-blind `CREATE` that
 * only legacy `object.write` satisfied, so a tenant created through the
 * panel could not save an object — the production 403 that opened this
 * ticket, hit by the tenant *owner*.
 *
 * Widening a gate is only honest with the denials that bound it, so each
 * grant below is paired with a role the matrix genuinely withholds it from:
 * `approver` may read products but not add them, and `marketing` may add
 * products, categories and its own multimedia but holds none of the generic
 * `object.*` verbs — which is what proves the gate reads the kind rather
 * than waving any catalog permission through.
 */
final class ObjectWriteAccessTest extends CatalogApiTestCase
{
    private const string CATALOG_EMAIL = 'objects-write-catalog@demo.localhost';
    private const string MARKETING_EMAIL = 'objects-write-marketing@demo.localhost';
    private const string APPROVER_EMAIL = 'objects-write-approver@demo.localhost';
    private const string VIEWER_EMAIL = 'objects-write-viewer@demo.localhost';

    /**
     * The symptom itself: a role holding the PRD create codes saves each
     * built-in kind through the endpoint the panel actually calls.
     */
    #[Test]
    public function catalogRoleCreatesEveryBuiltInKindThroughThePolyKindPost(): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');
        $client = $this->authenticatedClient(self::CATALOG_EMAIL);

        foreach ([ObjectKind::Product, ObjectKind::Category, ObjectKind::Asset] as $kind) {
            $body = [
                'code' => 'CM-'.$kind->value.'-1',
                'objectTypeId' => $this->objectTypeIdFor($kind),
            ];
            if (ObjectKind::Category === $kind) {
                // ADR-015 — categories name the tree they join.
                $body['categoryTargetObjectTypeId'] = $this->objectTypeIdFor(ObjectKind::Product);
            }

            $client->request('POST', '/api/objects', [
                'headers' => ['content-type' => 'application/ld+json'],
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
            ]);

            self::assertResponseStatusCodeSame(201, \sprintf('catalog_manager must create kind=%s', $kind->value));
        }
    }

    /**
     * `approver` holds `products.view` and no create code anywhere — the
     * control that keeps "accepts the PRD catalogue too" from becoming
     * "accepts anyone with a catalogue permission".
     */
    #[Test]
    public function aRoleWithoutTheCreateCodeIsStillRefused(): void
    {
        $this->givenUserWithRole(self::APPROVER_EMAIL, 'approver');
        $this->givenUserWithRole(self::VIEWER_EMAIL, 'viewer');

        foreach ([self::APPROVER_EMAIL, self::VIEWER_EMAIL] as $email) {
            $client = $this->authenticatedClient($email);
            foreach ([ObjectKind::Product, ObjectKind::Category, ObjectKind::Asset] as $kind) {
                $client->request('POST', '/api/objects', [
                    'headers' => ['content-type' => 'application/ld+json'],
                    'body' => json_encode([
                        'code' => 'DENY-'.$kind->value,
                        'objectTypeId' => $this->objectTypeIdFor($kind),
                    ], JSON_THROW_ON_ERROR),
                ]);

                self::assertResponseStatusCodeSame(403, \sprintf('%s must not create kind=%s', $email, $kind->value));
            }
        }
    }

    /**
     * A payload naming no readable ObjectType is the request "create
     * something, kind unknown" — genuinely broader than any single PRD code
     * answers, so it keeps requiring the legacy grant. Same shape as the
     * unscoped collection in {@see ObjectCollectionReadAccessTest}.
     */
    #[Test]
    public function aPayloadWithoutAReadableObjectTypeStillNeedsTheBroadGrant(): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');
        $client = $this->authenticatedClient(self::CATALOG_EMAIL);

        $client->request('POST', '/api/objects', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode(['code' => 'NO-TYPE'], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403, 'no objectTypeId means no kind to authorise');

        // An id from another tenant is hidden by the Doctrine TenantFilter,
        // so it is indistinguishable from a typo — both deny.
        $client->request('POST', '/api/objects', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'UNKNOWN-TYPE',
                'objectTypeId' => '019f0000-0000-7000-8000-000000000000',
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Deleting a product follows `products.delete`. Until now the product
     * voter spoke only the lowercase `delete` verb, and the gap stayed
     * hidden because `object.delete` collides between the legacy grid and
     * the ULV-04a verb set — so the three roles carrying that verb deleted
     * through the legacy voter by accident, and every other role could not
     * delete at all.
     */
    #[Test]
    public function deletingAProductFollowsTheProductCode(): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');
        $this->givenUserWithRole(self::MARKETING_EMAIL, 'marketing');

        $polyKindId = $this->givenProduct('DEL-POLY');
        $sugarPathId = $this->givenProduct('DEL-SUGAR');

        // marketing may add and edit products, but the matrix withholds
        // products.delete from it.
        $marketing = $this->authenticatedClient(self::MARKETING_EMAIL);
        $marketing->request('DELETE', '/api/objects/'.$polyKindId);
        self::assertResponseStatusCodeSame(403, 'marketing holds no products.delete');
        $marketing->request('DELETE', '/api/products/'.$sugarPathId);
        self::assertResponseStatusCodeSame(403, 'the sugar path follows the same code');

        $catalog = $this->authenticatedClient(self::CATALOG_EMAIL);
        $catalog->request('DELETE', '/api/objects/'.$polyKindId);
        self::assertResponseStatusCodeSame(204);
        $catalog->request('DELETE', '/api/products/'.$sugarPathId);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * Tenant-defined ObjectTypes were the one kind that never got a PRD
     * voter, so a custom module answered 403 to every PRD role on read and
     * on write alike. They follow the generic ULV-04a verbs (#985), which
     * `marketing` does not hold — so the same request that succeeds for a
     * catalog manager is refused for it.
     */
    #[Test]
    public function customKindsFollowTheGenericObjectVerbs(): void
    {
        $this->givenUserWithRole(self::CATALOG_EMAIL, 'catalog_manager');
        $this->givenUserWithRole(self::MARKETING_EMAIL, 'marketing');
        $customTypeId = $this->givenCustomObjectType();

        // Seeded by the super_admin fixture so the marketing denials below
        // have an existing instance to be refused on.
        $seeded = $this->authenticatedClient()->request('POST', '/api/objects', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'CUSTOM-SEED',
                'objectTypeId' => $customTypeId,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $seededId = $seeded->toArray()['id'];
        \assert(\is_string($seededId));

        // NOTE: the assertResponse* helpers read the most recently created
        // client, so each role's requests have to sit together after its own
        // authenticatedClient() call — interleaving two clients asserts
        // against the wrong response.
        $marketing = $this->authenticatedClient(self::MARKETING_EMAIL);
        $marketing->request('POST', '/api/objects', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'CUSTOM-DENY',
                'objectTypeId' => $customTypeId,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403, 'marketing holds none of the object.* verbs');
        $marketing->request('GET', '/api/objects?objectType='.$customTypeId);
        self::assertResponseStatusCodeSame(403, 'nor may it list a custom type');
        $marketing->request('GET', '/api/objects/'.$seededId);
        self::assertResponseStatusCodeSame(403, 'nor read one instance of it');

        $catalog = $this->authenticatedClient(self::CATALOG_EMAIL);
        $created = $catalog->request('POST', '/api/objects', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'CUSTOM-OK',
                'objectTypeId' => $customTypeId,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $customId = $created->toArray()['id'];
        \assert(\is_string($customId));

        $catalog->request('GET', '/api/objects?objectType='.$customTypeId);
        self::assertResponseStatusCodeSame(200);
        $catalog->request('GET', '/api/objects/'.$customId);
        self::assertResponseStatusCodeSame(200);
        $catalog->request('PATCH', '/api/objects/'.$customId, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'body' => json_encode(['code' => 'CUSTOM-OK-2'], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);
        $catalog->request('DELETE', '/api/objects/'.$customId);
        self::assertResponseStatusCodeSame(204);
    }

    private function givenProduct(string $code): string
    {
        $created = $this->authenticatedClient()->request('POST', '/api/objects', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => $code,
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);

        $id = $created->toArray()['id'];
        \assert(\is_string($id));

        return $id;
    }

    private function givenCustomObjectType(): string
    {
        // Custom ObjectTypes are feature-flagged out of the API in MVP
        // (ADR-009), so this seeds one the way BuiltInObjectTypeSeeder does:
        // tenant_id is stamped by TenantAssignmentListener on prePersist,
        // which needs the context set explicitly outside a request.
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($this->tenant());

        try {
            $type = new ObjectType('supplier', ObjectKind::Custom, ['pl' => 'Dostawca', 'en' => 'Supplier']);
            $this->em()->persist($type);
            $this->em()->flush();
            $id = $type->getId()->toRfc4122();
            $this->em()->clear();

            return $id;
        } finally {
            $tenantContext->clear();
        }
    }

    private function givenUserWithRole(string $email, string $roleCode): void
    {
        $tenant = $this->tenant();

        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        // ADR-0034 — addRole() is the single write path for assignments.
        $user->addRole($role);
        $this->em()->persist($user);
        $this->em()->flush();
        $this->em()->clear();
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }
}
