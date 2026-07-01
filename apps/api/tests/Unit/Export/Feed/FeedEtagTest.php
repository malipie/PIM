<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Application\Delivery\FeedEtag;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P3-05 — the public feed ETag is deterministic per cache generation.
 */
final class FeedEtagTest extends TestCase
{
    #[Test]
    public function etagIsQuotedAndDeterministic(): void
    {
        $at = new DateTimeImmutable('2026-07-01T20:02:43+00:00');

        $first = FeedEtag::forCache($at, 35291, 67);
        $second = FeedEtag::forCache($at, 35291, 67);

        self::assertSame($first, $second);
        self::assertStringStartsWith('"', $first);
        self::assertStringEndsWith('"', $first);
    }

    #[Test]
    public function etagChangesWhenTheCacheChanges(): void
    {
        $at = new DateTimeImmutable('2026-07-01T20:02:43+00:00');
        $base = FeedEtag::forCache($at, 35291, 67);

        self::assertNotSame($base, FeedEtag::forCache($at, 35292, 67));
        self::assertNotSame($base, FeedEtag::forCache($at, 35291, 68));
        self::assertNotSame($base, FeedEtag::forCache($at->modify('+1 second'), 35291, 67));
    }

    #[Test]
    public function etagToleratesAnEmptyCache(): void
    {
        self::assertNotSame('', FeedEtag::forCache(null, null, null));
    }
}
