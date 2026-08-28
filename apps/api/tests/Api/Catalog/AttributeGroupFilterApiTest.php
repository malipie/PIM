<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * DP-04 (#2034) — `GET /api/attributes?attributeGroup={uuid}` narrows the
 * list to attributes assigned to one AttributeGroup. Membership is
 * dual-path during the UI-08 transition: the M:N junction (ADR-012) OR the
 * legacy `attributes.group_id` FK (ADR-009) — the filter must match both.
 */
final class AttributeGroupFilterApiTest extends CatalogApiTestCase
{
    #[Test]
    public function filtersByJunctionMembership(): void
    {
        $client = $this->authenticatedClient();
        $this->seedAttribute('brand');
        $this->seedAttribute('warranty_months');

        $groupId = $this->createGroup($client, 'marketing');
        $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/bulk-attach', [
            'json' => ['attributeCodes' => ['brand']],
        ]);

        $codes = $this->listCodes($client, '/api/attributes?attributeGroup='.$groupId);

        self::assertContains('brand', $codes);
        self::assertNotContains('warranty_months', $codes);
    }

    #[Test]
    public function filtersByLegacyGroupFk(): void
    {
        $client = $this->authenticatedClient();
        $this->seedAttribute('color');

        $groupId = $this->createGroup($client, 'logistics');

        // Wire membership through the pre-ADR-012 FK only — no junction row.
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $attribute = $em->getRepository(Attribute::class)->findOneBy(['code' => 'color', 'tenant' => $tenant]);
        \assert($attribute instanceof Attribute);
        $group = $em->getRepository(\App\Catalog\Domain\Entity\AttributeGroup::class)->find(Uuid::fromString($groupId));
        \assert(null !== $group);
        $attribute->assignToGroup($group);
        $em->flush();

        $codes = $this->listCodes($client, '/api/attributes?attributeGroup='.$groupId);

        self::assertContains('color', $codes);
    }

    #[Test]
    public function unknownGroupUuidYieldsEmptyList(): void
    {
        $client = $this->authenticatedClient();
        $this->seedAttribute('size');

        $codes = $this->listCodes($client, '/api/attributes?attributeGroup='.Uuid::v7()->toRfc4122());

        self::assertSame([], $codes);
    }

    #[Test]
    public function malformedParamIsIgnored(): void
    {
        $client = $this->authenticatedClient();
        $this->seedAttribute('weight');

        $codes = $this->listCodes($client, '/api/attributes?attributeGroup=not-a-uuid');

        self::assertContains('weight', $codes);
    }

    /**
     * @return list<string>
     */
    private function listCodes(\ApiPlatform\Symfony\Bundle\Test\Client $client, string $url): array
    {
        $response = $client->request('GET', $url, ['headers' => ['Accept' => 'application/json']]);
        self::assertSame(200, $response->getStatusCode());

        /** @var list<array{code?: string}> $rows */
        $rows = $response->toArray();

        return array_map(static fn (array $row): string => $row['code'] ?? '', $rows);
    }

    private function createGroup(\ApiPlatform\Symfony\Bundle\Test\Client $client, string $code): string
    {
        $created = $client->request('POST', '/api/attribute_groups', [
            'json' => ['code' => $code, 'label' => ['pl' => $code]],
        ]);
        $groupId = $created->toArray()['id'] ?? null;
        \assert(\is_string($groupId));

        return $groupId;
    }

    private function seedAttribute(string $code): void
    {
        $ctx = self::getContainer()->get(TenantContext::class);
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $ctx->set($tenant);

        $attribute = new Attribute($code, ['en' => $code], AttributeType::Text);
        self::getContainer()->get(AttributeRepositoryInterface::class)->save($attribute);
    }
}
