<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * GOLIVE #2129 (red-team point 6) — the bulk-actions endpoint is gated by the
 * coarse `products.bulk_operations` permission, but each destructive action
 * must ALSO require its own grant. A red-team run found a Marketing user
 * (which holds `products.bulk_operations` but NOT `products.delete`) could
 * delete products via `POST /api/products/bulk-actions/delete`, while the
 * single-item `DELETE /api/products/{id}` correctly refused. This regression
 * pins the per-action check.
 */
final class BulkActionsPerActionPermissionApiTest extends CatalogApiTestCase
{
    private const string MARKETING_EMAIL = 'marketing-2129@demo.localhost';

    #[Test]
    public function marketingCannotBulkDeleteProducts(): void
    {
        $this->seedMarketingUser();
        $admin = $this->authenticatedClient();
        $productOt = $this->objectTypeIdFor(ObjectKind::Product);

        // Admin creates a product to target.
        $created = $admin->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'BULK-PERM-1',
                'objectTypeId' => $productOt,
                'attributes' => [],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $productId = $created->toArray()['id'];
        \assert(\is_string($productId));

        // Marketing (has bulk_operations, lacks products.delete) → 403.
        $marketing = $this->authenticatedClient(self::MARKETING_EMAIL);
        $marketing->request('POST', '/api/products/bulk-actions/delete', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'target_ids' => [$productId],
                'payload' => [],
                'confirmation_count' => 1,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403);

        // The product must still exist — the denied bulk op changed nothing.
        $admin->request('GET', '/api/products/'.$productId);
        self::assertResponseStatusCodeSame(200);

        // Control: the admin (super_admin → has products.delete) CAN bulk-delete.
        $admin->request('POST', '/api/products/bulk-actions/delete', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'target_ids' => [$productId],
                'payload' => [],
                'confirmation_count' => 1,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    private function seedMarketingUser(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $marketing = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('marketing', $tenant);
        \assert(null !== $marketing, 'marketing role must be seeded per tenant');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::MARKETING_EMAIL, '', ['ROLE_USER']);
        $user = new User($tenant, self::MARKETING_EMAIL, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $user->addRole($marketing);
        $em->persist($user);
        $em->flush();

        // Sanity: the fixture is only meaningful while marketing exists.
        $seeded = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::MARKETING_EMAIL);
        \assert($seeded instanceof User);
    }
}
