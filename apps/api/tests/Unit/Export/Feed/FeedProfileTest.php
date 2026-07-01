<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Enum\FeedStatus;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Enum\FeedValidationPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P1-01 — FeedProfile domain behaviour (defaults, enum round-trips,
 * lifecycle mutators).
 */
final class FeedProfileTest extends TestCase
{
    private function profile(): FeedProfile
    {
        return new FeedProfile(
            code: 'google_pl',
            name: 'Google Shopping — Polska',
            templateKind: FeedTemplateKind::GoogleShopping,
            objectTypeId: Uuid::v7(),
            descriptor: ['root' => ['element' => 'rss']],
        );
    }

    #[Test]
    public function newProfileHasSensibleDefaults(): void
    {
        $feed = $this->profile();

        self::assertSame('google_pl', $feed->getCode());
        self::assertSame(FeedTemplateKind::GoogleShopping, $feed->getTemplateKind());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame(FeedValidationPolicy::SkipInvalid, $feed->getValidationPolicy());
        self::assertSame([], $feed->getFieldMappings());
        self::assertNull($feed->getFilter());
        self::assertNull($feed->getTokenHash());
        self::assertNull($feed->getCachedFilePath());
        self::assertNull($feed->getLastRunId());
    }

    #[Test]
    public function pauseAndResumeToggleStatus(): void
    {
        $feed = $this->profile();

        $feed->pause();
        self::assertSame(FeedStatus::Paused, $feed->getStatus());

        $feed->resume();
        self::assertSame(FeedStatus::Active, $feed->getStatus());
    }

    #[Test]
    public function recordCacheStoresRunMetadataAndClearsError(): void
    {
        $feed = $this->profile();
        $feed->markError();
        self::assertSame(FeedStatus::Error, $feed->getStatus());

        $runId = Uuid::v7();
        $at = new DateTimeImmutable('2026-07-01T04:00:00+00:00');
        $feed->recordCache($runId, 'feeds/t/google_pl.xml', 18_700_000, 12_408, $at);

        self::assertTrue($feed->getLastRunId()?->equals($runId));
        self::assertSame('feeds/t/google_pl.xml', $feed->getCachedFilePath());
        self::assertSame(18_700_000, $feed->getCachedFileSize());
        self::assertSame(12_408, $feed->getCachedItemCount());
        self::assertSame($at, $feed->getCachedAt());
        // A successful regeneration clears a prior error status.
        self::assertSame(FeedStatus::Active, $feed->getStatus());
    }

    #[Test]
    public function scopeAndScheduleMutatorsUpdateFields(): void
    {
        $feed = $this->profile();
        $channelId = Uuid::v7();

        $feed->setScope($channelId, 'google_pl', 'pl', 'PLN');
        $feed->setScheduleCron('0 4 * * *');
        $feed->setValidationPolicy(FeedValidationPolicy::IncludeWithWarning);

        self::assertTrue($feed->getChannelId()?->equals($channelId));
        self::assertSame('google_pl', $feed->getPublicationChannel());
        self::assertSame('pl', $feed->getLocale());
        self::assertSame('PLN', $feed->getCurrency());
        self::assertSame('0 4 * * *', $feed->getScheduleCron());
        self::assertSame(FeedValidationPolicy::IncludeWithWarning, $feed->getValidationPolicy());
    }

    #[Test]
    public function customKindIsNotBuiltIn(): void
    {
        self::assertTrue(FeedTemplateKind::GoogleShopping->isBuiltIn());
        self::assertTrue(FeedTemplateKind::Ceneo->isBuiltIn());
        self::assertTrue(FeedTemplateKind::Meta->isBuiltIn());
        self::assertFalse(FeedTemplateKind::Custom->isBuiltIn());
    }
}
