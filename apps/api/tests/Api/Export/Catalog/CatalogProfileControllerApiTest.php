<?php

declare(strict_types=1);

namespace App\Tests\Api\Export\Catalog;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * CPDF-P1-03 — CatalogProfile CRUD (POST/GET/PATCH/DELETE /api/catalogs).
 *
 * Mirrors {@see \App\Tests\Api\Export\FeedProfileCrudApiTest} but for the PDF
 * catalog surface, sharing the export RBAC (`exports.view_all` on reads,
 * `integration.admin` on writes). Adds the auth-boundary cases the ticket
 * requires: 401 (anonymous), 403 (a `marketing` persona that holds neither the
 * read nor the write export grant), 404 (missing id) and body validation.
 */
final class CatalogProfileControllerApiTest extends CatalogApiTestCase
{
    private const string MARKETING_EMAIL = 'marketing-cpdf@demo.localhost';

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'template_kind' => 'sheet',
            'code' => 'b2b_catalog',
            'name' => 'Katalog B2B — Stalko',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'branding' => ['palette' => ['primary' => '#0a0a0a']],
            'field_mappings' => [['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']]],
            'filter' => ['op' => 'and', 'conditions' => []],
            'locale' => 'pl',
            'renderer' => 'auto',
        ];
    }

    #[Test]
    public function createsListsGetsPatchesAndDeletesACatalog(): void
    {
        $client = $this->authenticatedClient();

        $created = $client->request('POST', '/api/catalogs', ['json' => $this->payload()]);
        self::assertSame(201, $created->getStatusCode());
        $body = $created->toArray(false);
        self::assertSame('b2b_catalog', $body['code']);
        self::assertSame('sheet', $body['template_kind']);
        self::assertSame('active', $body['status']);
        self::assertSame('pl', $body['locale']);
        self::assertFalse($body['has_token']);
        self::assertArrayNotHasKey('token_hash', $body);
        $id = $body['id'];
        self::assertIsString($id);

        $listResponse = $client->request('GET', '/api/catalogs');
        self::assertSame(200, $listResponse->getStatusCode());
        $items = $listResponse->toArray(false)['items'];
        self::assertIsArray($items);
        self::assertNotEmpty($items);
        self::assertContains($id, array_column($items, 'id'));
        self::assertStringNotContainsString('token_hash', $listResponse->getContent(false));

        $get = $client->request('GET', '/api/catalogs/'.$id);
        self::assertSame(200, $get->getStatusCode());

        $patched = $client->request('PATCH', '/api/catalogs/'.$id, [
            'json' => ['name' => 'Katalog B2B — v2', 'template_kind' => 'grid', 'status' => 'paused'],
        ]);
        self::assertSame(200, $patched->getStatusCode());
        $patchedBody = $patched->toArray(false);
        self::assertSame('Katalog B2B — v2', $patchedBody['name']);
        self::assertSame('grid', $patchedBody['template_kind']);
        self::assertSame('paused', $patchedBody['status']);
        self::assertArrayNotHasKey('token_hash', $patchedBody);

        self::assertSame(204, $client->request('DELETE', '/api/catalogs/'.$id)->getStatusCode());
        self::assertSame(404, $client->request('GET', '/api/catalogs/'.$id)->getStatusCode());
    }

    #[Test]
    public function rejectsAMissingCode(): void
    {
        $client = $this->authenticatedClient();
        $payload = $this->payload();
        unset($payload['code']);

        self::assertSame(400, $client->request('POST', '/api/catalogs', ['json' => $payload])->getStatusCode());
    }

    #[Test]
    public function rejectsAnUnknownTemplateKind(): void
    {
        $client = $this->authenticatedClient();
        $payload = $this->payload();
        $payload['code'] = 'bad_kind_catalog';
        $payload['template_kind'] = 'not_a_kind';

        self::assertSame(400, $client->request('POST', '/api/catalogs', ['json' => $payload])->getStatusCode());
    }

    #[Test]
    public function unknownIdIsA404(): void
    {
        $client = $this->authenticatedClient();

        self::assertSame(
            404,
            $client->request('GET', '/api/catalogs/00000000-0000-7000-8000-000000000000')->getStatusCode(),
        );
    }

    #[Test]
    public function anonymousRequestsAre401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/catalogs');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aPersonaWithoutTheExportGrantsIsForbidden(): void
    {
        $marketingJwt = $this->seedMarketingUser();
        // API Platform's test client only supports a single active kernel —
        // swap the Bearer per request rather than calling createClient() twice.
        $client = static::createClient();
        $adminJwt = $this->jwtFor(self::ADMIN_EMAIL);

        // Admin creates a catalog to target (super_admin holds every grant).
        $created = $client->request('POST', '/api/catalogs', [
            'headers' => ['authorization' => 'Bearer '.$adminJwt, 'content-type' => 'application/json'],
            'body' => json_encode($this->payload(), JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $created->toArray()['id'];
        \assert(\is_string($id));

        // Marketing lacks exports.view_all → read endpoints refuse with 403.
        $client->request('GET', '/api/catalogs', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/catalogs/'.$id, [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);

        // Marketing lacks integration.admin → write endpoints refuse with 403.
        $writePayload = $this->payload();
        $writePayload['code'] = 'marketing_denied';
        $client->request('POST', '/api/catalogs', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt, 'content-type' => 'application/json'],
            'body' => json_encode($writePayload, JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/api/catalogs/'.$id, [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);

        // The denied write changed nothing — the admin still sees the catalog.
        $client->request('GET', '/api/catalogs/'.$id, [
            'headers' => ['authorization' => 'Bearer '.$adminJwt],
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
