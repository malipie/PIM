<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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
        $marketingJwt = $this->seedMarketingUser();
        // ONE client for the whole test — API Platform's test client only
        // supports a single active kernel; swap the Bearer per request
        // instead of calling createClient() twice (the second reboot
        // invalidates the first client's handle).
        $client = static::createClient();
        $adminJwt = $this->jwtFor(self::ADMIN_EMAIL);
        $productOt = $this->objectTypeIdFor(ObjectKind::Product);

        // Admin creates a product to target.
        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json', 'authorization' => 'Bearer '.$adminJwt],
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
        $client->request('POST', '/api/products/bulk-actions/delete', [
            'headers' => ['content-type' => 'application/json', 'authorization' => 'Bearer '.$marketingJwt],
            'body' => json_encode([
                'target_ids' => [$productId],
                'payload' => [],
                'confirmation_count' => 1,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403);

        // The product must still exist — the denied bulk op changed nothing.
        $client->request('GET', '/api/products/'.$productId, [
            'headers' => ['authorization' => 'Bearer '.$adminJwt],
        ]);
        self::assertResponseStatusCodeSame(200);

        // Control: the admin (super_admin → has products.delete) CAN bulk-delete.
        $client->request('POST', '/api/products/bulk-actions/delete', [
            'headers' => ['content-type' => 'application/json', 'authorization' => 'Bearer '.$adminJwt],
            'body' => json_encode([
                'target_ids' => [$productId],
                'payload' => [],
                'confirmation_count' => 1,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    private function jwtFor(string $email): string
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert($user instanceof User);

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function seedMarketingUser(): string
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

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
