<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application\Delivery;

use DateTimeImmutable;

/**
 * Deterministic ETag for a catalog's cached PDF (CPDF-P1-04, used by the public
 * pull in CPDF-P3-02). Derived from the cache generation stamp + byte size +
 * page count, so it changes exactly when a regeneration replaces the file and
 * lets a puller skip the body with a conditional `If-None-Match` → `304`.
 */
final class CatalogEtag
{
    public static function forCache(?DateTimeImmutable $cachedAt, ?int $size, ?int $pageCount): string
    {
        $seed = ($cachedAt?->getTimestamp() ?? 0).'-'.($size ?? 0).'-'.($pageCount ?? 0);

        return '"'.substr(hash('sha256', $seed), 0, 24).'"';
    }
}
