<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Generator;

use App\Export\Feed\Domain\Entity\FeedProfile;

/**
 * Seam over the product value source a feed projects (ADR-0023 §6.3, XMLF-P2-04).
 * Yields one attribute-code-keyed map per product, memory-bounded (the
 * implementation owns chunking + EntityManager::clear()). The ExportBuilder-backed
 * adapter — which resolves the feed's scope (locale/channel/filter) into an
 * ExportSession — lands with XMLF-P3-02, so the generator core here stays
 * unit-testable against a fake source.
 */
interface FeedProductValues
{
    /**
     * @return iterable<array<string, string>> attribute code => serialized value, per product
     */
    public function forProfile(FeedProfile $profile): iterable;
}
