<?php

declare(strict_types=1);

namespace App\Catalog\Application\Filter;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;

/**
 * VIEW-10 (#538) — resolves attribute type for FilterDslResolver per-request.
 *
 * Each filter condition references an attribute by code (`brand`, `price`,
 * `description.pl` etc.). To validate the operator (`STARTS WITH` only on
 * `text`, `between` only on numeric / date, etc.) the resolver needs the
 * attribute's domain type. Hitting the DB once per condition would be
 * O(N) at panel apply time — the resolver caches per-request in-memory.
 *
 * Special cases:
 *   - Reserved attribute codes mapped to fixed types (system columns):
 *     `completeness_pct` → number, `enabled` → boolean, `sku` → text,
 *     `category` → relation, `main_image` → asset.
 *   - Locale-scoped paths (`description.pl`, `description.en`) strip the
 *     locale before lookup; the underlying `description` attribute owns
 *     the type.
 *   - Unknown attribute code returns `null` — the resolver surfaces that
 *     as a Problem Details `unsafe_identifier` 400 rather than guessing.
 */
final class AttributeMetadataResolver
{
    /**
     * @var array<string, string>
     */
    private const array RESERVED_TYPES = [
        'completeness_pct' => 'number',
        'enabled' => 'boolean',
        'sku' => 'text',
        'category' => 'relation',
        'main_image' => 'asset',
        // #2526 — editorial state enum (draft/review/published/archived).
        // `select` gives =, !=, IN, NOT IN so users can scope exports/feeds/
        // lists by status via the advanced filter.
        'status' => 'select',
    ];

    /**
     * @var array<string, ?string>
     */
    private array $cache = [];

    /**
     * #2673 — full meta cache (id + capability flags) for the scoped
     * filter path. Kept separate from the type cache: reserved codes have
     * a type but no meta (they map to physical columns, never to
     * `object_values` rows).
     *
     * @var array<string, ?AttributeMeta>
     */
    private array $metaCache = [];

    public function __construct(
        private readonly AttributeRepositoryInterface $attributes,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Return the type string (one of {@see AttributeType} cases) for the
     * given attribute code, or `null` when the code is unknown. The
     * locale-scoped form `<code>.<locale>` is normalised to `<code>`.
     */
    public function getAttributeType(string $code): ?string
    {
        $base = $this->stripLocaleSuffix($code);

        if (\array_key_exists($base, $this->cache)) {
            return $this->cache[$base];
        }

        if (isset(self::RESERVED_TYPES[$base])) {
            return $this->cache[$base] = self::RESERVED_TYPES[$base];
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return $this->cache[$base] = null;
        }

        $attribute = $this->attributes->findByCode($base, $tenant);
        if (null === $attribute) {
            return $this->cache[$base] = null;
        }

        return $this->cache[$base] = $attribute->getType()->value;
    }

    /**
     * #2673 — full attribute meta for the scoped filter path, or `null`
     * for reserved/system codes (column-mapped, never scoped), dotted
     * locale paths and unknown codes.
     */
    public function getAttributeMeta(string $code): ?AttributeMeta
    {
        $base = $this->stripLocaleSuffix($code);

        if (\array_key_exists($base, $this->metaCache)) {
            return $this->metaCache[$base];
        }

        if (isset(self::RESERVED_TYPES[$base])) {
            return $this->metaCache[$base] = null;
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return $this->metaCache[$base] = null;
        }

        $attribute = $this->attributes->findByCode($base, $tenant);
        if (null === $attribute) {
            return $this->metaCache[$base] = null;
        }

        return $this->metaCache[$base] = new AttributeMeta(
            id: $attribute->getId()->toRfc4122(),
            type: $attribute->getType()->value,
            localizable: $attribute->isLocalizable(),
            scopable: $attribute->isScopable(),
        );
    }

    /**
     * @internal for tests
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->metaCache = [];
    }

    private function stripLocaleSuffix(string $code): string
    {
        $dot = strpos($code, '.');
        if (false === $dot) {
            return $code;
        }

        return substr($code, 0, $dot);
    }
}
