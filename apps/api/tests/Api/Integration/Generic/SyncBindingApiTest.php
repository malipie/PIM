<?php

declare(strict_types=1);

namespace App\Tests\Api\Integration\Generic;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Channel\Domain\Entity\Channel;
use App\Identity\Domain\Entity\User;
use App\Shared\Domain\Tenant;
use App\Tests\Api\ApiConfigurator\ApiConfiguratorApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * APIC-P3-10 — CRUD coverage for `/api/sync_bindings` plus the run / pause /
 * resume procedural actions. Reuses the ApiConfigurator RBAC scaffold (admin is
 * Super Admin; the limited user has no integration permission).
 */
final class SyncBindingApiTest extends ApiConfiguratorApiTestCase
{
    private const string LD_JSON = 'application/ld+json';
    private const string MERGE_PATCH = 'application/merge-patch+json';
    private const string OBJECT_TYPE_ID = '0192a000-0000-7000-8000-000000000001';

    #[Test]
    public function postCreatesBinding(): void
    {
        $connectionId = $this->createConnection();

        $body = $this->createBinding($connectionId, [
            'direction' => 'inbound',
            'schedule' => '0 2 * * *',
            'conflictPolicy' => 'lww',
            'matchKeyMapping' => 'sku',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($connectionId, $body['connectionId'] ?? null);
        self::assertSame(self::OBJECT_TYPE_ID, $body['objectTypeId'] ?? null);
        self::assertSame('inbound', $body['direction'] ?? null);
        self::assertSame('0 2 * * *', $body['schedule'] ?? null);
        self::assertSame('lww', $body['conflictPolicy'] ?? null);
        self::assertTrue($body['isEnabled'] ?? null);
        // A scheduled binding gets its next run computed on create.
        self::assertNotNull($body['nextRun'] ?? null);
    }

    #[Test]
    public function manualBindingHasNoNextRun(): void
    {
        $connectionId = $this->createConnection();

        $body = $this->createBinding($connectionId, ['schedule' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($body['nextRun'] ?? null);
    }

    #[Test]
    public function patchUpdatesBinding(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createBinding($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $body = $this->authenticatedClient()->request('PATCH', '/api/sync_bindings/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['conflictPolicy' => 'pim_wins', 'direction' => 'bidirectional'], JSON_THROW_ON_ERROR),
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('pim_wins', $body['conflictPolicy'] ?? null);
        self::assertSame('bidirectional', $body['direction'] ?? null);
    }

    #[Test]
    public function patchSetsSourceScopeAndEmptyStringClearsIt(): void
    {
        // #2667 — outbound value-source scope: set both codes, then clear with
        // the empty-string idiom. A null field is OMITTED from the response
        // (API Platform skips nulls), hence the `?? null` assertions.
        $this->seedChannel('shopify');
        $connectionId = $this->createConnection();
        $id = $this->createBinding($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $client = $this->authenticatedClient();
        $body = $client->request('PATCH', '/api/sync_bindings/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['sourceChannel' => 'shopify', 'sourceLocale' => 'en'], JSON_THROW_ON_ERROR),
        ])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('shopify', $body['sourceChannel'] ?? null);
        self::assertSame('en', $body['sourceLocale'] ?? null);

        $cleared = $client->request('PATCH', '/api/sync_bindings/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['sourceChannel' => '', 'sourceLocale' => ''], JSON_THROW_ON_ERROR),
        ])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertNull($cleared['sourceChannel'] ?? null);
        self::assertNull($cleared['sourceLocale'] ?? null);
    }

    #[Test]
    public function postWithSourceScopeEchoesIt(): void
    {
        $this->seedChannel('allegro');
        $connectionId = $this->createConnection();

        $body = $this->createBinding($connectionId, ['sourceChannel' => 'allegro', 'sourceLocale' => 'pl']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('allegro', $body['sourceChannel'] ?? null);
        self::assertSame('pl', $body['sourceLocale'] ?? null);
    }

    #[Test]
    public function patchUnknownSourceChannelIs422(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createBinding($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $this->authenticatedClient()->request('PATCH', '/api/sync_bindings/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['sourceChannel' => 'nope'], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function patchCrossTenantSourceChannelIs422(): void
    {
        // The resolver is tenant-scoped: another tenant's channel code must not
        // resolve here.
        $other = new Tenant('other-'.uniqid(), 'Other');
        $channel = new Channel('foreign', 'Foreign');
        $channel->assignTenant($other);
        $em = $this->em();
        $em->persist($other);
        $em->persist($channel);
        $em->flush();

        $connectionId = $this->createConnection();
        $id = $this->createBinding($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $this->authenticatedClient()->request('PATCH', '/api/sync_bindings/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['sourceChannel' => 'foreign'], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function patchUnknownSourceLocaleIs422(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createBinding($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $this->authenticatedClient()->request('PATCH', '/api/sync_bindings/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['sourceLocale' => 'xx'], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function deleteBindingThenGetIs404(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createBinding($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $client = $this->authenticatedClient();
        $client->request('DELETE', '/api/sync_bindings/'.$id);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/sync_bindings/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function bindingsListIsScopedByConnection(): void
    {
        $first = $this->createConnection('alpha');
        $second = $this->createConnection('beta');
        $this->createBinding($first);
        $this->createBinding($second);

        $body = $this->authenticatedClient()
            ->request('GET', '/api/sync_bindings?connection='.$first)
            ->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $body['totalItems'] ?? null);
    }

    #[Test]
    public function runDispatchesBinding(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createBinding($connectionId, ['direction' => 'inbound', 'schedule' => '0 2 * * *'])['id'] ?? null;
        \assert(\is_string($id));

        $body = $this->authenticatedClient()->request('POST', '/api/sync_bindings/'.$id.'/run')->toArray();

        self::assertResponseStatusCodeSame(202);
        self::assertTrue($body['dispatched'] ?? null);
        self::assertSame('inbound', $body['direction'] ?? null);
    }

    #[Test]
    public function pauseThenResumeTogglesEnabled(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createBinding($connectionId, ['schedule' => '0 2 * * *'])['id'] ?? null;
        \assert(\is_string($id));

        $client = $this->authenticatedClient();

        $paused = $client->request('POST', '/api/sync_bindings/'.$id.'/pause')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($paused['enabled'] ?? null);

        $after = $client->request('GET', '/api/sync_bindings/'.$id)->toArray();
        self::assertFalse($after['isEnabled'] ?? null);
        self::assertNull($after['nextRun'] ?? null);

        $resumed = $client->request('POST', '/api/sync_bindings/'.$id.'/resume')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($resumed['enabled'] ?? null);
        self::assertNotNull($resumed['next_run'] ?? null);
    }

    #[Test]
    public function runUnknownBindingIs404(): void
    {
        $this->authenticatedClient()->request('POST', '/api/sync_bindings/01234567-1234-7000-8000-000000000000/run');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function postWithInvalidDirectionIs422(): void
    {
        $connectionId = $this->createConnection();

        $this->authenticatedClient()->request('POST', '/api/sync_bindings', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode([
                'connection' => $connectionId,
                'objectTypeId' => self::OBJECT_TYPE_ID,
                'direction' => 'sideways',
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function unauthenticatedListIs401(): void
    {
        static::createClient()->request('GET', '/api/sync_bindings');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function limitedUserListIs403(): void
    {
        $this->limitedClient()->request('GET', '/api/sync_bindings');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function seedChannel(string $code): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $channel = new Channel($code, ucfirst($code));
        $channel->assignTenant($tenant);
        $this->em()->persist($channel);
        $this->em()->flush();
    }

    private function createConnection(string $code = 'idosell'): string
    {
        $body = $this->authenticatedClient()->request('POST', '/api/connections', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode([
                'code' => $code,
                'name' => ucfirst($code),
                'baseUrl' => 'https://api.idosell.com',
                'authType' => 'none',
            ], JSON_THROW_ON_ERROR),
        ])->toArray(false);

        $id = $body['id'] ?? null;
        \assert(\is_string($id));

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function createBinding(string $connectionId, array $overrides = []): array
    {
        $payload = array_merge([
            'connection' => $connectionId,
            'objectTypeId' => self::OBJECT_TYPE_ID,
            'direction' => 'inbound',
            'conflictPolicy' => 'lww',
        ], $overrides);

        return $this->authenticatedClient()->request('POST', '/api/sync_bindings', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->toArray(false);
    }

    private function limitedClient(): Client
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $email = 'limited-binding@demo.localhost';
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwt]]);

        return $client;
    }
}
