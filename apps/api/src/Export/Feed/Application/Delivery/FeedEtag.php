<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Delivery;

use DateTimeImmutable;

/**
 * Deterministic ETag for a feed's cached artifact (XMLF-P3-05). Derived from the
 * cache generation stamp + size + item count, so it changes exactly when a
 * regeneration replaces the file and lets a puller (Google/Ceneo) skip the body
 * with a conditional `If-None-Match` → `304`.
 */
final class FeedEtag
{
    public static function forCache(?DateTimeImmutable $cachedAt, ?int $size, ?int $itemCount): string
    {
        $seed = ($cachedAt?->getTimestamp() ?? 0).'-'.($size ?? 0).'-'.($itemCount ?? 0);

        return '"'.substr(hash('sha256', $seed), 0, 24).'"';
    }
}
