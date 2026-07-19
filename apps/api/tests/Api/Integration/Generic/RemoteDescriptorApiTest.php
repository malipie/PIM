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
 * APIC-P2-05 — CRUD coverage for `/api/remote_endpoints` + `/api/remote_fields`
 * and the `POST /api/connections/{id}/discover` trigger. Reuses the
 * ApiConfigurator RBAC scaffold (super_admin + tenant_owner admin). The discover
 * happy path is unit-covered (SchemaDiscoveryServiceTest); here the probe target
 * is a loopback host so the SSRF wall makes the failure path deterministic and
 * offline.
 */
final class RemoteDescriptorApiTest extends ApiConfiguratorApiTestCase
{
    private const string LD_JSON = 'application/ld+json';
    private const string MERGE_PATCH = 'application/merge-patch+json';

    #[Test]
    public function postCreatesEndpoint(): void
    {
        $connectionId = $this->createConnection();

        $body = $this->createEndpoint($connectionId, [
            'role' => 'read_list',
            'pathTemplate' => '/products',
            'pagination' => ['strategy' => 'offset', 'limit' => 50],
            'recordSelector' => '$.results',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('read_list', $body['role'] ?? null);
        self::assertSame('/products', $body['pathTemplate'] ?? null);
        self::assertSame('$.results', $body['recordSelector'] ?? null);
        self::assertSame($connectionId, $body['connectionId'] ?? null);
    }

    #[Test]
    public function postEndpointRejectsSchemeInPathTemplate(): void
    {
        $connectionId = $this->createConnection();

        $this->authenticatedClient()->request('POST', '/api/remote_endpoints', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode([
                'connection' => $connectionId,
                'role' => 'read_list',
                'httpMethod' => 'GET',
                'pathTemplate' => 'https://evil.example/products',
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function postCreatesFormEndpointWithBodyTemplate(): void
    {
        // #2634 — RPC-style write endpoint: form wire format + static envelope.
        $connectionId = $this->createConnection();

        $body = $this->createEndpoint($connectionId, [
            'role' => 'write_create',
            'httpMethod' => 'POST',
            'pathTemplate' => '/connector.php',
            'requestFormat' => 'form',
            'requestBodyTemplate' => ['method' => 'addInventoryProduct', 'parameters' => ['inventory_id' => 1234]],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('form', $body['requestFormat'] ?? null);
        self::assertSame(
            ['method' => 'addInventoryProduct', 'parameters' => ['inventory_id' => 1234]],
            $body['requestBodyTemplate'] ?? null,
        );
    }

    #[Test]
    public function postAndPatchCarryRemoteIdCaptureFields(): void
    {
        // #2636 — remote-id capture config: JSONPath into the write response +
        // the PIM attribute the captured id is stored in.
        $connectionId = $this->createConnection();

        $body = $this->createEndpoint($connectionId, [
            'role' => 'write_create',
            'httpMethod' => 'POST',
            'pathTemplate' => '/connector.php',
            'responseIdSelector' => '$.product_id',
            'responseIdAttribute' => 'base_product_id',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('$.product_id', $body['responseIdSelector'] ?? null);
        self::assertSame('base_product_id', $body['responseIdAttribute'] ?? null);

        $id = $body['id'] ?? null;
        \assert(\is_string($id));
        $patched = $this->authenticatedClient()->request('PATCH', '/api/remote_endpoints/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['responseIdSelector' => '', 'responseIdAttribute' => ''], JSON_THROW_ON_ERROR),
        ])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertNull($patched['responseIdSelector'] ?? null);
        self::assertNull($patched['responseIdAttribute'] ?? null);
    }

    #[Test]
    public function postEndpointRejectsUnknownRequestFormat(): void
    {
        $connectionId = $this->createConnection();

        $this->createEndpoint($connectionId, ['requestFormat' => 'xml']);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function patchEndpointUpdatesRequestFormatAndTemplate(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createEndpoint($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $body = $this->authenticatedClient()->request('PATCH', '/api/remote_endpoints/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode([
                'requestFormat' => 'form',
                'requestBodyTemplate' => ['method' => 'updateInventoryProductsStock'],
            ], JSON_THROW_ON_ERROR),
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('form', $body['requestFormat'] ?? null);
        self::assertSame(['method' => 'updateInventoryProductsStock'], $body['requestBodyTemplate'] ?? null);
    }

    #[Test]
    public function postEndpointUnknownConnectionIs404(): void
    {
        $this->authenticatedClient()->request('POST', '/api/remote_endpoints', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode([
                'connection' => '01234567-1234-7000-8000-000000000000',
                'role' => 'read_list',
                'httpMethod' => 'GET',
                'pathTemplate' => '/products',
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function patchEndpointUpdatesRoleAndPath(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createEndpoint($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $body = $this->authenticatedClient()->request('PATCH', '/api/remote_endpoints/'.$id, [
            'headers' => ['content-type' => self::MERGE_PATCH],
            'body' => json_encode(['role' => 'read_one', 'pathTemplate' => '/products/{id}'], JSON_THROW_ON_ERROR),
        ])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('read_one', $body['role'] ?? null);
        self::assertSame('/products/{id}', $body['pathTemplate'] ?? null);
    }

    #[Test]
    public function deleteEndpointThenGetIs404(): void
    {
        $connectionId = $this->createConnection();
        $id = $this->createEndpoint($connectionId)['id'] ?? null;
        \assert(\is_string($id));

        $client = $this->authenticatedClient();
        $client->request('DELETE', '/api/remote_endpoints/'.$id);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/remote_endpoints/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function endpointsListIsScopedByConnection(): void
    {
        $first = $this->createConnection('alpha');
        $second = $this->createConnection('beta');
        $this->createEndpoint($first, ['pathTemplate' => '/a']);
        $this->createEndpoint($second, ['pathTemplate' => '/b']);

        $body = $this->authenticatedClient()
            ->request('GET', '/api/remote_endpoints?connection='.$first)
            ->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $body['totalItems'] ?? null);
    }

    #[Test]
    public function postCreatesField(): void
    {
        $connectionId = $this->createConnection();
        $endpointId = $this->createEndpoint($connectionId)['id'] ?? null;
        \assert(\is_string($endpointId));

        $body = $this->authenticatedClient()->request('POST', '/api/remote_fields', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode([
                'endpoint' => $endpointId,
                'path' => '$.price.amount',
                'label' => 'Price',
                'dataType' => 'integer',
                'sampleValue' => '1999',
            ], JSON_THROW_ON_ERROR),
        ])->toArray(false);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('$.price.amount', $body['path'] ?? null);
        self::assertSame('integer', $body['dataType'] ?? null);
        self::assertSame($endpointId, $body['endpointId'] ?? null);
    }

    #[Test]
    public function fieldsListIsScopedByEndpoint(): void
    {
        $connectionId = $this->createConnection();
        $endpointId = $this->createEndpoint($connectionId)['id'] ?? null;
        \assert(\is_string($endpointId));
        $this->createField($endpointId, '$.sku');
        $this->createField($endpointId, '$.name');

        $body = $this->authenticatedClient()
            ->request('GET', '/api/remote_fields?endpoint='.$endpointId)
            ->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $body['totalItems'] ?? null);
    }

    #[Test]
    public function discoverRequiresEndpointField(): void
    {
        $connectionId = $this->createConnection();

        $this->authenticatedClient()->request('POST', '/api/connections/'.$connectionId.'/discover', [
            'headers' => ['content-type' => 'application/json'],
            'body' => '{}',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function discoverUnknownConnectionIs404(): void
    {
        $this->authenticatedClient()->request('POST', '/api/connections/01234567-1234-7000-8000-000000000000/discover', [
            'headers' => ['content-type' => 'application/json'],
            'body' => '{"endpoint":"01234567-1234-7000-8000-000000000001"}',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function discoverUnreachableEndpointIs422(): void
    {
        // Loopback base URL passes descriptor validation but the SSRF wall
        // rejects it at fetch time → 422 (offline, deterministic).
        $connectionId = $this->createConnection('local', 'http://127.0.0.1/api');
        $endpointId = $this->createEndpoint($connectionId)['id'] ?? null;
        \assert(\is_string($endpointId));

        $this->authenticatedClient()->request('POST', '/api/connections/'.$connectionId.'/discover', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['endpoint' => $endpointId], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function unauthenticatedEndpointsListIs401(): void
    {
        static::createClient()->request('GET', '/api/remote_endpoints');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function limitedUserEndpointsListIs403(): void
    {
        $this->limitedClient()->request('GET', '/api/remote_endpoints');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function createConnection(string $code = 'idosell', string $baseUrl = 'https://api.idosell.com'): string
    {
        $body = $this->authenticatedClient()->request('POST', '/api/connections', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode([
                'code' => $code,
                'name' => ucfirst($code),
                'baseUrl' => $baseUrl,
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
    private function createEndpoint(string $connectionId, array $overrides = []): array
    {
        $payload = array_merge([
            'connection' => $connectionId,
            'role' => 'read_list',
            'httpMethod' => 'GET',
            'pathTemplate' => '/products',
        ], $overrides);

        return $this->authenticatedClient()->request('POST', '/api/remote_endpoints', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])->toArray(false);
    }

    private function createField(string $endpointId, string $path): void
    {
        $this->authenticatedClient()->request('POST', '/api/remote_fields', [
            'headers' => ['content-type' => self::LD_JSON],
            'body' => json_encode([
                'endpoint' => $endpointId,
                'path' => $path,
                'dataType' => 'string',
            ], JSON_THROW_ON_ERROR),
        ]);
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
