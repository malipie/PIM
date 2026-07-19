<?php

declare(strict_types=1);

namespace App\Tests\Api\Integration\Generic;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Identity\Domain\Entity\User;
use App\Shared\Domain\Tenant;
use App\Tests\Api\ApiConfigurator\ApiConfiguratorApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * APIC-P2-08 — CRUD coverage for `/api/field_mappings` plus the
 * `POST /api/connections/{id}/mappings/validate` trigger (match-key rule +
 * type warnings). Reuses the ApiConfigurator RBAC scaffold.
 */
final class FieldMappingApiTest extends ApiConfiguratorApiTestCase
{
    private const string LD_JSON = 'application/ld+json';
    private const string MERGE_PATCH = 'application/merge-patch+json';

    #[Test]
    public function postCreatesMapping(): void
    {
        $connectionId = $this->createConnection();

        $body = $this->createMapping($connectionId, [
            'pimTarget' => 'sku',
            'remoteFieldPath' => '$.sku',
            'direction' => 'both',
            'isMatchKey' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('sku', $body['pimTarget'] ?? null);
        self::assertSame('$.sku', $body['remoteFieldPath'] ?? null);
        self::assertSame('both', $body['direction'] ?? null);
        self::assertTrue($body['isMatchKey'] ?? null);
        self::assertSame(1, $body['version'] ?? null);
        self::assertSame($connectionId, $body['connectionId'] ?? null);
    }

    #[Test]
    public function patchBumpsVersion(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createMapping($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $body = $this->authenticatedClient()->request('PATCH', '/api/field_mappings/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['direction' => 'outbound'], JSON_THROW_ON_ERROR),
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('outbound', $body['direction'] ?? null);
        self::assertSame(2, $body['version'] ?? null);
    }

    #[Test]
    public function postCreatesEndpointScopedMapping(): void
    {
        // #2634 — a mapping may be scoped to one endpoint (RPC operations shape
        // the payload per method); null scope keeps the applies-to-all default.
        $connectionId = $this->createConnection();
        $endpointId = $this->createEndpoint($connectionId);

        $body = $this->createMapping($connectionId, [
            'direction' => 'outbound',
            'endpoint' => $endpointId,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($endpointId, $body['endpointId'] ?? null);
    }

    #[Test]
    public function postMappingRejectsEndpointOfAnotherConnection(): void
    {
        $connectionId = $this->createConnection();
        $otherEndpointId = $this->createEndpoint($this->createConnection('beta'));

        $this->createMapping($connectionId, ['endpoint' => $otherEndpointId]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function patchClearsEndpointScopeWithEmptyString(): void
    {
        $connectionId = $this->createConnection();
        $endpointId = $this->createEndpoint($connectionId);
        $id = $this->createMapping($connectionId, ['endpoint' => $endpointId])['id'] ?? null;
        \assert(\is_string($id));

        $body = $this->authenticatedClient()->request('PATCH', '/api/field_mappings/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['endpoint' => ''], JSON_THROW_ON_ERROR),
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertNull($body['endpointId'] ?? null);
    }

    #[Test]
    public function deleteMappingThenGetIs404(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createMapping($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $client = $this->authenticatedClient();
        $client->request('DELETE', '/api/field_mappings/'.$id);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/field_mappings/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function mappingsListIsScopedByConnection(): void
    {
        $first = $this->createConnection('alpha');
        $second = $this->createConnection('beta');
        $this->createMapping($first, ['pimTarget' => 'sku', 'remoteFieldPath' => '$.sku']);
        $this->createMapping($second, ['pimTarget' => 'name', 'remoteFieldPath' => '$.name']);

        $body = $this->authenticatedClient()
            ->request('GET', '/api/field_mappings?connection='.$first)
            ->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $body['totalItems'] ?? null);
    }

    #[Test]
    public function validateFlagsInboundWithoutMatchKey(): void
    {
        $connectionId = $this->createConnection();
        $this->createMapping($connectionId, [
            'pimTarget' => 'name',
            'remoteFieldPath' => '$.name',
            'direction' => 'inbound',
            'isMatchKey' => false,
        ]);

        $body = $this->authenticatedClient()
            ->request('POST', '/api/connections/'.$connectionId.'/mappings/validate', [
                'headers' => ['content-type' => 'application/json'],
                'body' => '{}',
            ])
            ->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($body['valid'] ?? null);
        self::assertNotEmpty($body['errors'] ?? []);
    }

    #[Test]
    public function validatePassesWithAMatchKey(): void
    {
        $connectionId = $this->createConnection();
        $this->createMapping($connectionId, [
            'pimTarget' => 'sku',
            'remoteFieldPath' => '$.sku',
            'direction' => 'inbound',
            'isMatchKey' => true,
        ]);

        $body = $this->authenticatedClient()
            ->request('POST', '/api/connections/'.$connectionId.'/mappings/validate', [
                'headers' => ['content-type' => 'application/json'],
                'body' => '{}',
            ])
            ->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($body['valid'] ?? null);
    }

    #[Test]
    public function validateUnknownConnectionIs404(): void
    {
        $this->authenticatedClient()->request(
            'POST',
            '/api/connections/01234567-1234-7000-8000-000000000000/mappings/validate',
            ['headers' => ['content-type' => 'application/json'], 'body' => '{}'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function unauthenticatedListIs401(): void
    {
        static::createClient()->request('GET', '/api/field_mappings');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function limitedUserListIs403(): void
    {
        $this->limitedClient()->request('GET', '/api/field_mappings');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
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

    private function createEndpoint(string $connectionId): string
    {
        $body = $this->authenticatedClient()->request('POST', '/api/remote_endpoints', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode([
                'connection' => $connectionId,
                'role' => 'write_create',
                'httpMethod' => 'POST',
                'pathTemplate' => '/connector.php',
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
    private function createMapping(string $connectionId, array $overrides = []): array
    {
        $payload = array_merge([
            'connection' => $connectionId,
            'pimTarget' => 'sku',
            'remoteFieldPath' => '$.sku',
            'direction' => 'inbound',
            'isMatchKey' => false,
        ], $overrides);

        return $this->authenticatedClient()->request('POST', '/api/field_mappings', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->toArray(false);
    }

    private function limitedClient(): Client
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $email = 'limited@demo.localhost';
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
