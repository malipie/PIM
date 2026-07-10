<?php

declare(strict_types=1);

namespace App\Tests\Api\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Agent\Domain\Entity\ContentRecipe;
use App\DataFixtures\Identity\PrdPermissionFixtures;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AICG-P1-03 (#2329, ADR-0030) — /api/content-recipes +
 * /api/brand-voice-profiles CRUD surface with the settings.ai_content
 * module: 401 / 403 (viewer without the module; marketing without
 * admin) / 404 / validation / happy path, built-in read-only + clone
 * route, transactional default swap, tenant isolation.
 */
final class AiContentSettingsApiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string MARKETING_EMAIL = 'marketing@demo.localhost';
    private const string VIEWER_EMAIL = 'viewer@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        $prdPermissions = new PrdPermissionFixtures();
        $prdPermissions->load($em);
        $em->flush();

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $this->createUser($tenant, self::ADMIN_EMAIL, 'tenant_owner');
        $this->createUser($tenant, self::MARKETING_EMAIL, 'marketing');
        $this->createUser($tenant, self::VIEWER_EMAIL, 'viewer');
    }

    #[Test]
    public function anonymousRequestsAreRejectedWith401(): void
    {
        $response = static::createClient()->request('GET', '/api/content-recipes');
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function viewerWithoutTheModuleGets403OnReads(): void
    {
        $response = $this->authenticatedClient(self::VIEWER_EMAIL)->request('GET', '/api/content-recipes');
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function marketingCanReadAndCreateButNotAdminister(): void
    {
        $client = $this->authenticatedClient(self::MARKETING_EMAIL);

        $created = $client->request('POST', '/api/content-recipes', ['json' => [
            'code' => 'marketing_recipe',
            'name' => 'Opis marketingowy',
            'targetAttribute' => 'description',
            'sourceAttributes' => ['material'],
            'constraints' => ['format' => 'plain'],
        ]]);
        self::assertSame(201, $created->getStatusCode());
        $id = $created->toArray()['id'];
        self::assertIsString($id);

        self::assertSame(200, $client->request('GET', '/api/content-recipes')->getStatusCode());

        // 3rd state: create without admin — PATCH and DELETE stay closed.
        $patch = $client->request('PATCH', '/api/content-recipes/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Zmieniony'],
        ]);
        self::assertSame(403, $patch->getStatusCode());
        self::assertSame(403, $client->request('DELETE', '/api/content-recipes/'.$id)->getStatusCode());
    }

    #[Test]
    public function missingRecipeIs404(): void
    {
        $response = $this->authenticatedClient()->request('GET', '/api/content-recipes/'.Uuid::v7()->toRfc4122());
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function structuralAndAggregateValidationSurfaceAs422(): void
    {
        $client = $this->authenticatedClient();

        $badCode = $client->request('POST', '/api/content-recipes', ['json' => [
            'code' => 'Bad Code!',
            'name' => 'X',
            'targetAttribute' => 'description',
        ]]);
        self::assertSame(422, $badCode->getStatusCode());

        $badFormat = $client->request('POST', '/api/content-recipes', ['json' => [
            'code' => 'bad_format',
            'name' => 'X',
            'targetAttribute' => 'description',
            'constraints' => ['format' => 'markdown'],
        ]]);
        self::assertSame(422, $badFormat->getStatusCode());
        $detail = $badFormat->toArray(false)['detail'] ?? '';
        self::assertIsString($detail);
        self::assertStringContainsString('constraints.format', $detail);
    }

    #[Test]
    public function recipeCrudHappyPathRoundTrips(): void
    {
        $client = $this->authenticatedClient();

        $created = $client->request('POST', '/api/content-recipes', ['json' => [
            'code' => 'product_description',
            'name' => 'Opis produktu',
            'targetAttribute' => 'description',
            'sourceAttributes' => ['material', 'color'],
            'constraints' => ['format' => 'html', 'max_len' => 1200, 'seo' => ['keyword' => 'HDMI']],
            'toneHint' => 'ekspercki',
        ]]);
        self::assertSame(201, $created->getStatusCode());
        $payload = $created->toArray();
        $id = $payload['id'];
        self::assertIsString($id);
        self::assertSame('product_description', $payload['code']);
        self::assertSame(['material', 'color'], $payload['sourceAttributes']);
        self::assertFalse($payload['builtIn']);

        $duplicate = $client->request('POST', '/api/content-recipes', ['json' => [
            'code' => 'product_description',
            'name' => 'Duplikat',
            'targetAttribute' => 'description',
        ]]);
        self::assertSame(409, $duplicate->getStatusCode());

        $patched = $client->request('PATCH', '/api/content-recipes/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Opis produktu v2', 'sourceAttributes' => ['material', 'color', 'brand']],
        ]);
        self::assertSame(200, $patched->getStatusCode());
        self::assertSame('Opis produktu v2', $patched->toArray()['name']);
        self::assertSame(['material', 'color', 'brand'], $patched->toArray()['sourceAttributes']);

        self::assertSame(204, $client->request('DELETE', '/api/content-recipes/'.$id)->getStatusCode());
        self::assertSame(404, $client->request('GET', '/api/content-recipes/'.$id)->getStatusCode());
    }

    #[Test]
    public function builtInRecipesAreReadOnlyAndCloneable(): void
    {
        $client = $this->authenticatedClient();
        $id = $this->seedBuiltInRecipe()->toRfc4122();

        $patch = $client->request('PATCH', '/api/content-recipes/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Nadpisany'],
        ]);
        self::assertSame(409, $patch->getStatusCode());
        self::assertSame(409, $client->request('DELETE', '/api/content-recipes/'.$id)->getStatusCode());

        $clone = $client->request('POST', '/api/content-recipes/'.$id.'/clone', ['json' => []]);
        self::assertSame(201, $clone->getStatusCode());
        $clonePayload = $clone->toArray();
        self::assertIsString($clonePayload['id']);
        self::assertSame('builtin_recipe_copy', $clonePayload['code']);
        self::assertFalse($clonePayload['is_built_in']);

        $editClone = $client->request('PATCH', '/api/content-recipes/'.$clonePayload['id'], [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Kopia po edycji'],
        ]);
        self::assertSame(200, $editClone->getStatusCode());
    }

    #[Test]
    public function brandVoiceCrudWithTransactionalDefaultSwap(): void
    {
        $client = $this->authenticatedClient();

        $first = $client->request('POST', '/api/brand-voice-profiles', ['json' => [
            'name' => 'Ekspercki',
            'tone' => 'ekspercki, zwięzły',
            'glossary' => [['term' => 'smart TV', 'use' => 'telewizor smart']],
            'bannedWords' => ['tani'],
            'examples' => [['good' => 'Precyzyjny opis.', 'bad' => 'Super okazja!!!']],
            'isDefault' => true,
        ]]);
        self::assertSame(201, $first->getStatusCode());
        $firstId = $first->toArray()['id'];
        self::assertIsString($firstId);
        self::assertTrue($first->toArray()['default']);

        $second = $client->request('POST', '/api/brand-voice-profiles', ['json' => [
            'name' => 'Swobodny',
            'tone' => 'lekki, bezpośredni',
            'isDefault' => true,
        ]]);
        self::assertSame(201, $second->getStatusCode());
        self::assertTrue($second->toArray()['default']);

        // The swap must have cleared the first default in the same request.
        $firstAfter = $client->request('GET', '/api/brand-voice-profiles/'.$firstId);
        self::assertSame(200, $firstAfter->getStatusCode());
        self::assertFalse($firstAfter->toArray()['default']);
    }

    #[Test]
    public function malformedGlossaryIs422(): void
    {
        $response = $this->authenticatedClient()->request('POST', '/api/brand-voice-profiles', ['json' => [
            'name' => 'Zepsuty',
            'tone' => 'x',
            'glossary' => [['term' => 'bez-use']],
        ]]);
        self::assertSame(422, $response->getStatusCode());
        $detail = $response->toArray(false)['detail'] ?? '';
        self::assertIsString($detail);
        self::assertStringContainsString('glossary', $detail);
    }

    #[Test]
    public function recipesAreInvisibleAcrossTenants(): void
    {
        $em = $this->em();
        $other = new Tenant('beta', 'Beta Tenant');
        $em->persist($other);
        $em->flush();
        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($other);
        $this->createUser($other, 'owner@beta.localhost', 'tenant_owner');

        $this->authenticatedClient()->request('POST', '/api/content-recipes', ['json' => [
            'code' => 'demo_only',
            'name' => 'Demo only',
            'targetAttribute' => 'description',
        ]]);

        $betaList = $this->authenticatedClient('owner@beta.localhost')->request('GET', '/api/content-recipes');
        self::assertSame(200, $betaList->getStatusCode());
        $payload = $betaList->toArray();
        self::assertSame(0, $payload['totalItems'] ?? $payload['hydra:totalItems'] ?? -1);
    }

    private function authenticatedClient(string $email = self::ADMIN_EMAIL): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert(null !== $user);

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwt]]);

        return $client;
    }

    private function createUser(Tenant $tenant, string $email, string $roleCode): void
    {
        $em = $this->em();
        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $user->addRole($role);
        $em->persist($user);
        $em->flush();
    }

    private function seedBuiltInRecipe(): Uuid
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $recipe = new ContentRecipe(
            code: 'builtin_recipe',
            name: 'Systemowy przepis',
            targetAttribute: 'description',
            sourceAttributes: ['material'],
            constraints: ['format' => 'html'],
        );
        $recipe->markBuiltIn();
        $em->persist($recipe);
        $em->flush();

        return $recipe->getId();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
