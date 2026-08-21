<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * #2943 — `GET /api/object_types/{id}/next-code`.
 *
 * The create form will not save without an identifier, and a custom module
 * has no external number to copy, so operators were inventing one per row.
 * The endpoint suggests the next free one; the form prefills it and the
 * operator overwrites it when the identifier comes from an ERP.
 */
final class ObjectTypeNextCodeApiTest extends CatalogApiTestCase
{
    private string $typeId;

    private ObjectType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $em = $this->em();
        $this->type = new ObjectType('tworcy', ObjectKind::Custom, ['pl' => 'Twórcy']);
        $em->persist($this->type);
        $em->flush();

        $this->typeId = $this->type->getId()->toRfc4122();
    }

    #[Test]
    public function firstSuggestionForAnEmptyTypeStartsAtOne(): void
    {
        $response = $this->authenticatedClient()->request('GET', "/api/object_types/{$this->typeId}/next-code", [
            'headers' => ['accept' => 'application/json'],
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('tworcy_000001', $response->toArray()['code'] ?? null);
    }

    #[Test]
    public function suggestionContinuesFromTheHighestNumberInUse(): void
    {
        $this->seedObject('tworcy_000001');
        $this->seedObject('tworcy_000007');

        $response = $this->authenticatedClient()->request('GET', "/api/object_types/{$this->typeId}/next-code", [
            'headers' => ['accept' => 'application/json'],
        ]);

        // Not "count + 1": deleting one of seven must not hand out a number
        // that still exists in someone's export.
        self::assertSame('tworcy_000008', $response->toArray()['code'] ?? null);
    }

    #[Test]
    public function operatorSuppliedCodesUnderTheSamePrefixDoNotBreakTheCounter(): void
    {
        // A hand-typed identifier is the whole point of keeping the field
        // editable; it must not be parsed as the counter.
        $this->seedObject('tworcy_LEM');
        $this->seedObject('tworcy_000003');

        $response = $this->authenticatedClient()->request('GET', "/api/object_types/{$this->typeId}/next-code", [
            'headers' => ['accept' => 'application/json'],
        ]);

        self::assertSame('tworcy_000004', $response->toArray()['code'] ?? null);
    }

    #[Test]
    public function unknownObjectTypeReturns404(): void
    {
        $response = $this->authenticatedClient()->request(
            'GET',
            '/api/object_types/01a02340-0000-7000-8000-000000000000/next-code',
            ['headers' => ['accept' => 'application/json']],
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticatedAccessReturns401(): void
    {
        $response = static::createClient()->request('GET', "/api/object_types/{$this->typeId}/next-code", [
            'headers' => ['accept' => 'application/json'],
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    private function seedObject(string $code): void
    {
        $em = $this->em();
        $em->persist(new CatalogObject($this->type, $code));
        $em->flush();
    }
}
