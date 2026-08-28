<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\AttributeReadRestrictionOverlay;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Identity\Contracts\Policy\AttributePermissionReader;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AUD-008 (#1578) correctness + #1620 perf-regression guard.
 *
 * The overlay strips per-attribute `restricted` columns from the read
 * response. On the collection path it MUST resolve the tenant attribute
 * catalogue once and reuse each attribute's view decision across items —
 * the per-item resolution introduced by #1578 turned a 200-item page into
 * N×(findAllByTenant + canViewAttribute) and timed out the Playwright
 * multi-tenant-isolation spec at 10s.
 */
final class AttributeReadRestrictionOverlayTest extends TestCase
{
    #[Test]
    public function dropsRestrictedAttributeAndKeepsVisibleOnes(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Text);
        $price = new Attribute('purchase_price', ['en' => 'Price'], AttributeType::Number);

        $attributes = new CountingAttributeRepository([$color, $price]);
        // `purchase_price` restricted, everything else viewable.
        $permissions = new CountingPermissionReader([$price->getId()]);

        $overlay = new AttributeReadRestrictionOverlay($attributes, $permissions);

        $object = $this->object($tenant, [
            'color' => ['value' => 'red'],
            'purchase_price' => ['value' => 19.99],
            'created_at' => ['value' => '2026-01-01'], // unknown code → system attr → kept
        ]);

        $result = $overlay->apply($object);
        $indexed = $result->getAttributesIndexed();

        self::assertArrayNotHasKey('purchase_price', $indexed, 'restricted attribute must be dropped');
        self::assertSame(['value' => 'red'], $indexed['color'] ?? null);
        self::assertArrayHasKey('created_at', $indexed, 'system attribute must pass through');
    }

    #[Test]
    public function batchResolvesCatalogueOnceAndMemoisesViewDecisionPerAttribute(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Text);
        $price = new Attribute('purchase_price', ['en' => 'Price'], AttributeType::Number);

        $attributes = new CountingAttributeRepository([$color, $price]);
        $permissions = new CountingPermissionReader([$price->getId()]);

        $overlay = new AttributeReadRestrictionOverlay($attributes, $permissions);

        // 200 items, each carrying the same two attribute codes — the exact
        // shape `GET /api/products?itemsPerPage=200` produced.
        $objects = [];
        for ($i = 0; $i < 200; ++$i) {
            $objects[] = $this->object($tenant, [
                'color' => ['value' => 'red'],
                'purchase_price' => ['value' => 19.99],
            ]);
        }

        $result = $overlay->applyBatch($objects);

        self::assertCount(200, $result);
        // The N+1 the regression introduced: one catalogue load + one view
        // decision per attribute id — NOT per item.
        self::assertSame(1, $attributes->findAllByTenantCalls, 'findAllByTenant must run once for the whole page');
        self::assertSame(
            2,
            $permissions->canViewCalls,
            'canViewAttribute must be resolved once per unique attribute id, not per item',
        );

        foreach ($result as $object) {
            $indexed = $object->getAttributesIndexed();
            self::assertArrayNotHasKey('purchase_price', $indexed);
            self::assertArrayHasKey('color', $indexed);
        }
    }

    /**
     * @param array<string, mixed> $indexed
     */
    private function object(Tenant $tenant, array $indexed): CatalogObject
    {
        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $object = new CatalogObject($type, 'SKU-'.bin2hex(random_bytes(4)));
        $object->assignTenant($tenant);
        $object->updateAttributeIndex($indexed);

        return $object;
    }
}

/**
 * @internal counts findAllByTenant calls so the batch path can assert the
 *           per-page catalogue load happens exactly once (#1620)
 */
final class CountingAttributeRepository implements AttributeRepositoryInterface
{
    public int $findAllByTenantCalls = 0;

    /**
     * @param list<Attribute> $attributes
     */
    public function __construct(private readonly array $attributes)
    {
    }

    public function findAllByTenant(Tenant $tenant): array
    {
        ++$this->findAllByTenantCalls;

        return $this->attributes;
    }

    public function findById(Uuid $id): ?Attribute
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->getId()->equals($id)) {
                return $attribute;
            }
        }

        return null;
    }

    public function findByCode(string $code, Tenant $tenant): ?Attribute
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->getCode() === $code) {
                return $attribute;
            }
        }

        return null;
    }

    public function save(Attribute $attribute): void
    {
    }

    public function remove(Attribute $attribute): void
    {
    }

    public function filterableCodes(): array
    {
        return [];
    }
}

/**
 * @internal counts permission lookups so the batch path can assert each
 *           attribute id is resolved once, not once per item (#1620), and
 *           that the whole catalogue costs a single batch call (#2794)
 */
final class CountingPermissionReader implements AttributePermissionReader
{
    public int $canViewCalls = 0;

    public int $canViewBatchCalls = 0;

    /** @var list<string> attribute ids (RFC4122) the principal may NOT view */
    private readonly array $denied;

    /**
     * @param list<Uuid> $deniedIds
     */
    public function __construct(array $deniedIds)
    {
        $this->denied = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $deniedIds);
    }

    public function canViewAttribute(Uuid $attributeId): bool
    {
        ++$this->canViewCalls;

        return !\in_array($attributeId->toRfc4122(), $this->denied, true);
    }

    public function canViewAttributes(array $attributeIds): array
    {
        ++$this->canViewBatchCalls;

        $decisions = [];
        foreach ($attributeIds as $attributeId) {
            ++$this->canViewCalls;
            $decisions[$attributeId->toRfc4122()] = !\in_array($attributeId->toRfc4122(), $this->denied, true);
        }

        return $decisions;
    }

    public function canEditAttribute(Uuid $attributeId): bool
    {
        return $this->canViewAttribute($attributeId);
    }

    public function isAttributePermissionEnforced(): bool
    {
        return true;
    }
}
