<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * GRID-P5-02 (#2398) — `?order[attribute.{code}]` on the object list:
 * numeric ordering (not lexicographic), NULLS LAST, deterministic id
 * tie-breaker, stable LIMIT/OFFSET pagination, and a uniform 400 for
 * unknown / localizable (unsortable) codes per ADR-0028.
 */
final class AttributeOrderFilterApiTest extends CatalogApiTestCase
{
    #[Test]
    public function sortsNumericallyWithNullsLastAndPaginatesStably(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $code = 'sort_weight_'.$suffix;
        $this->seedCatalogue($code, AttributeType::Number, [
            ['SORTA-'.$suffix, 20],
            ['SORTB-'.$suffix, 3],
            ['SORTC-'.$suffix, 100],
            ['SORTD-'.$suffix, null],
        ]);

        $client = $this->authenticatedClient();
        $asc = $client->request('GET', '/api/objects?order[attribute.'.$code.']=asc&itemsPerPage=50')->toArray();
        $codes = self::memberCodes($asc, $suffix);
        // 3 < 20 < 100 numerically (lexicographic would be 100 < 20 < 3); null last.
        self::assertSame(
            ['SORTB-'.$suffix, 'SORTA-'.$suffix, 'SORTC-'.$suffix, 'SORTD-'.$suffix],
            $codes,
        );

        $desc = $client->request('GET', '/api/objects?order[attribute.'.$code.']=desc&itemsPerPage=50')->toArray();
        $descCodes = self::memberCodes($desc, $suffix);
        self::assertSame(
            ['SORTC-'.$suffix, 'SORTA-'.$suffix, 'SORTB-'.$suffix, 'SORTD-'.$suffix],
            $descCodes,
        );

        // LIMIT/OFFSET pages do not duplicate or drop rows under the sort.
        $page1 = $client->request('GET', '/api/objects?order[attribute.'.$code.']=asc&itemsPerPage=2&page=1')->toArray();
        $page2 = $client->request('GET', '/api/objects?order[attribute.'.$code.']=asc&itemsPerPage=2&page=2')->toArray();
        $ids1 = self::memberStrings($page1, 'id');
        $ids2 = self::memberStrings($page2, 'id');
        self::assertSame([], array_intersect($ids1, $ids2));
    }

    #[Test]
    public function rejectsUnknownAndUnsortableCodesUniformly(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $loc = 'sort_loc_'.$suffix;
        $this->seedCatalogue($loc, AttributeType::Text, [], localizable: true);

        $client = $this->authenticatedClient();

        $unknown = $client->request('GET', '/api/objects?order[attribute.nope_'.$suffix.']=asc');
        self::assertSame(400, $unknown->getStatusCode());

        $localizable = $client->request('GET', '/api/objects?order[attribute.'.$loc.']=asc');
        self::assertSame(400, $localizable->getStatusCode());

        $badDirection = $client->request('GET', '/api/objects?order[attribute.'.$loc.']=sideways');
        self::assertSame(400, $badDirection->getStatusCode());
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<string>
     */
    private static function memberCodes(array $payload, string $suffix): array
    {
        $codes = [];
        foreach (self::memberRows($payload) as $row) {
            $code = $row['code'] ?? null;
            if (\is_string($code) && str_contains($code, $suffix)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<string>
     */
    private static function memberStrings(array $payload, string $field): array
    {
        $values = [];
        foreach (self::memberRows($payload) as $row) {
            $value = $row[$field] ?? null;
            if (\is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array<mixed>>
     */
    private static function memberRows(array $payload): array
    {
        $member = $payload['member'] ?? $payload['hydra:member'] ?? [];
        self::assertIsArray($member);
        $rows = [];
        foreach ($member as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array{0: string, 1: int|null}> $objects
     */
    private function seedCatalogue(
        string $attributeCode,
        AttributeType $type,
        array $objects,
        bool $localizable = false,
    ): void {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $attribute = new Attribute($attributeCode, ['en' => $attributeCode], $type);
        $attribute->changeLocalizable($localizable);

        $em = $this->em();
        $em->persist($attribute);
        $em->persist(new ObjectTypeAttribute($productType, $attribute, false, 50));

        foreach ($objects as [$code, $value]) {
            $object = new CatalogObject($productType, $code);
            if (null !== $value) {
                $object->updateAttributeIndex([$attributeCode => ['value' => $value]]);
            }
            $em->persist($object);
        }
        $em->flush();
        $tenantContext->clear();
    }
}
