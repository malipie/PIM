<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * WFL-P6-02 (#2435) — POST /api/products must honour the PRD §3.2 matrix:
 * `products.add` gates product creation. Before this fix the operation asked
 * for the generic 'CREATE' attribute, ProductVoter only spoke lowercase
 * verbs (plus the WFL-P1-02 'UPDATE' alias), so PRD-only principals
 * (marketing, catalog_manager) fell through to the legacy CatalogObjectVoter
 * and were denied for lacking `object.write`.
 *
 * The fix is kind-safe on purpose: the products sugar path asks for
 * 'CREATE_PRODUCT' instead of aliasing 'CREATE' in ProductVoter, because at
 * POST time the subject is the bare class string (no kind) and /categories +
 * /objects share the 'CREATE' attribute — a generic alias would leak
 * products.add onto them (GOLIVE #2129 escalation shape). The escalation
 * guards below pin that boundary.
 */
final class ProductCreatePermissionApiTest extends CatalogApiTestCase
{
    private const string MARKETING_EMAIL = 'marketing-2435@demo.localhost';

    #[Test]
    public function marketingCreatesProductButCannotCreateOtherKinds(): void
    {
        $marketingJwt = $this->seedMarketingUser();
        $client = static::createClient();
        $productOt = $this->objectTypeIdFor(ObjectKind::Product);

        // products.add grants the products sugar path.
        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json', 'authorization' => 'Bearer '.$marketingJwt],
            'body' => json_encode([
                'code' => 'MKT-CREATE-1',
                'objectTypeId' => $productOt,
                'attributes' => [],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('MKT-CREATE-1', $created->toArray()['code'] ?? null);
        $productId = $created->toArray()['id'];
        \assert(\is_string($productId));

        // Escalation guard: products.add must NOT leak onto the generic
        // /api/objects POST (still gated by legacy object.write)...
        $client->request('POST', '/api/objects', [
            'headers' => ['content-type' => 'application/ld+json', 'authorization' => 'Bearer '.$marketingJwt],
            'body' => json_encode([
                'code' => 'MKT-CREATE-2',
                'objectTypeId' => $productOt,
                'attributes' => [],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403);

        // ...nor onto the categories sugar path.
        $client->request('POST', '/api/categories', [
            'headers' => ['content-type' => 'application/ld+json', 'authorization' => 'Bearer '.$marketingJwt],
            'body' => json_encode([
                'code' => 'MKT-CREATE-CAT-1',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Category),
                'attributes' => [],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403);

        // products.view grants the products reads (READ_PRODUCT alias) —
        // the item GET is what the product-detail page stands on.
        $client->request('GET', '/api/products/'.$productId, [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(200);
        $client->request('GET', '/api/products', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(200);

        // Reads must not leak onto the generic /api/objects list either.
        $client->request('GET', '/api/objects', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);

        // The poly-kind item GET is what the admin detail page stands on —
        // the instance-checked 'READ' alias grants it for product kinds...
        $client->request('GET', '/api/objects/'.$productId, [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(200);

        // ...but a non-product instance stays behind the legacy gate.
        $adminJwt = $this->jwtFor(self::ADMIN_EMAIL);
        $category = $client->request('POST', '/api/categories', [
            'headers' => ['content-type' => 'application/ld+json', 'authorization' => 'Bearer '.$adminJwt],
            'body' => json_encode([
                'code' => 'MKT-READ-CAT-1',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Category),
                'categoryTargetObjectTypeId' => $productOt,
                'attributes' => [],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $categoryId = $category->toArray()['id'];
        \assert(\is_string($categoryId));

        $client->request('GET', '/api/objects/'.$categoryId, [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    private function jwtFor(string $email): string
    {
        $user = self::getContainer()->get(\App\Identity\Domain\Repository\UserRepositoryInterface::class)->findByEmail($email);
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
