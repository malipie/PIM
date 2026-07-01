<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Channel\Contracts\ChannelCategoryExternalCodeResolverInterface;
use App\Export\Contracts\FeedProductScope;
use App\Export\Contracts\FeedProductValues;
use App\Export\Feed\Application\Async\FeedCancelledException;
use App\Export\Feed\Application\Generator\FeedGenerator;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Entity\FeedRunLog;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Generator\FeedRequiredValidator;
use App\Export\Feed\Domain\Mapping\FeedItemMapper;
use App\Export\Feed\Domain\Mapping\FeedTransformApplier;
use App\Export\Feed\Domain\Repository\FeedRunLogRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P2-04 — FeedGenerator orchestration: maps + validates + serializes each
 * product, applies the skip policy, and records the FeedRun counters + logs.
 */
final class FeedGeneratorTest extends TestCase
{
    private function profile(): FeedProfile
    {
        return new FeedProfile(
            code: 'custom_pl',
            name: 'Custom Feed',
            templateKind: FeedTemplateKind::Custom,
            objectTypeId: Uuid::v7(),
            descriptor: [
                'root' => ['element' => 'products'],
                'item' => [
                    'element' => 'product',
                    'slots' => [
                        ['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text'],
                        ['slot' => 'name', 'node' => 'element', 'fmt' => 'text'],
                    ],
                ],
            ],
            fieldMappings: [
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                ['slot' => 'name', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
            ],
        );
    }

    #[Test]
    public function generatesFeedApplyingSkipPolicyAndCounters(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-feed-');
        self::assertIsString($path);

        $source = new class implements FeedProductValues {
            public function forScope(FeedProductScope $scope): iterable
            {
                yield ['sku' => 'KL-1', 'name' => 'Wkręt'];
                yield ['sku' => 'KL-2', 'name' => 'Kątownik'];
                yield ['name' => 'Bez SKU']; // missing required sku → skipped
            }
        };

        $runRepo = new class implements FeedRunRepositoryInterface {
            public function save(FeedRun $run): void
            {
            }

            public function findById(Uuid $id): ?FeedRun
            {
                return null;
            }

            public function findByFeedProfile(Uuid $feedProfileId, int $limit = 50): array
            {
                return [];
            }

            public function findPage(?Uuid $feedProfileId, ?string $health, ?Uuid $cursor, int $limit): array
            {
                return [];
            }

            public function kpi24h(\App\Shared\Domain\Tenant $tenant, DateTimeImmutable $now): array
            {
                return ['regenerations_24h' => 0, 'skipped_24h' => 0, 'errors_24h' => 0, 'last_error' => null];
            }
        };

        $logRepo = new class implements FeedRunLogRepositoryInterface {
            public int $saved = 0;

            public function save(FeedRunLog $log): void
            {
                ++$this->saved;
            }

            public function saveMany(array $logs): void
            {
                $this->saved += \count($logs);
            }

            public function findByRun(Uuid $feedRunId): array
            {
                return [];
            }

            public function findPageByRun(Uuid $feedRunId, ?string $level, ?Uuid $cursor, int $limit): array
            {
                return [];
            }
        };

        try {
            $generator = new FeedGenerator($source, new FeedItemMapper(new FeedTransformApplier()), new FeedRequiredValidator(), $runRepo, $logRepo, $this->stubResolver());
            $run = $generator->generate($this->profile(), $path, FeedRunTrigger::Manual);

            self::assertSame(FeedRunStatus::Done, $run->getStatus());
            self::assertSame(2, $run->getItemCount());
            self::assertSame(1, $run->getSkippedCount());
            self::assertGreaterThanOrEqual(1, $run->getWarningCount());
            self::assertGreaterThanOrEqual(1, $logRepo->saved);

            $dom = new DOMDocument();
            self::assertNotFalse($dom->loadXML((string) file_get_contents($path)));
            self::assertSame(2, $dom->getElementsByTagName('product')->length);
            self::assertSame('KL-1', $dom->getElementsByTagName('sku')->item(0)?->textContent);
        } finally {
            @unlink($path);
        }
    }

    /**
     * XMLF-P4-02 — a FeedCancelledException thrown by the progress callback
     * passes through WITHOUT flipping the run to error: the cancel endpoint
     * already persisted the terminal status, and overwriting `cancelled`
     * with `error` would misreport a user decision as a failure.
     */
    #[Test]
    public function cancellationFromTheProgressCallbackDoesNotMarkTheRunAsError(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-feed-');
        self::assertIsString($path);

        $source = new class implements FeedProductValues {
            public function forScope(FeedProductScope $scope): iterable
            {
                for ($i = 1; $i <= 500; ++$i) {
                    yield ['sku' => 'KL-'.$i, 'name' => 'Produkt '.$i];
                }
            }
        };

        $runRepo = new class implements FeedRunRepositoryInterface {
            public ?FeedRun $lastSaved = null;

            public function save(FeedRun $run): void
            {
                $this->lastSaved = $run;
            }

            public function findById(Uuid $id): ?FeedRun
            {
                return null;
            }

            public function findByFeedProfile(Uuid $feedProfileId, int $limit = 50): array
            {
                return [];
            }

            public function findPage(?Uuid $feedProfileId, ?string $health, ?Uuid $cursor, int $limit): array
            {
                return [];
            }

            public function kpi24h(\App\Shared\Domain\Tenant $tenant, DateTimeImmutable $now): array
            {
                return ['regenerations_24h' => 0, 'skipped_24h' => 0, 'errors_24h' => 0, 'last_error' => null];
            }
        };

        $logRepo = new class implements FeedRunLogRepositoryInterface {
            public function save(FeedRunLog $log): void
            {
            }

            public function saveMany(array $logs): void
            {
            }

            public function findByRun(Uuid $feedRunId): array
            {
                return [];
            }

            public function findPageByRun(Uuid $feedRunId, ?string $level, ?Uuid $cursor, int $limit): array
            {
                return [];
            }
        };

        $profile = $this->profile();
        $run = new FeedRun($profile->getId(), FeedRunTrigger::Manual);
        $ticks = [];
        $onChunk = static function (int $processed) use (&$ticks): void {
            $ticks[] = $processed;
            // Simulate the cancel endpoint having flipped the persisted status.
            throw new FeedCancelledException('Feed regeneration cancelled by the user.');
        };

        try {
            $generator = new FeedGenerator($source, new FeedItemMapper(new FeedTransformApplier()), new FeedRequiredValidator(), $runRepo, $logRepo, $this->stubResolver());

            try {
                $generator->generate($profile, $path, FeedRunTrigger::Manual, $run, $onChunk);
                self::fail('FeedCancelledException must propagate to the caller.');
            } catch (FeedCancelledException) {
                // expected
            }

            self::assertSame([200], $ticks, 'first tick at the 200-row cadence, then stop');
            self::assertNotSame(FeedRunStatus::Error, $run->getStatus(), 'cancellation is not a failure');
            self::assertSame($run, $runRepo->lastSaved, 'no other run instance was created');
        } finally {
            @unlink($path);
        }
    }

    /**
     * XMLF-P3-03 — fmt=category slots resolve the RECEIVER's category id from
     * the product's master categories through the Channel contract: first
     * mapped category with an external_code wins; an explicit mapping value
     * still takes precedence; a required slot left unresolved skips the item
     * (with the validator's warning), an optional one is omitted with its own
     * health-trail warning.
     */
    #[Test]
    public function categorySlotsResolveThroughTheChannelContract(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-feed-');
        self::assertIsString($path);

        $catMapped = Uuid::v7()->toRfc4122();
        $catUnmapped = Uuid::v7()->toRfc4122();
        $channelId = Uuid::v7();

        $source = new class($catMapped, $catUnmapped) implements FeedProductValues {
            public ?FeedProductScope $seenScope = null;

            public function __construct(private readonly string $mapped, private readonly string $unmapped)
            {
            }

            public function forScope(FeedProductScope $scope): iterable
            {
                $this->seenScope = $scope;
                // Resolved through the channel port (second category carries the code).
                yield ['sku' => 'KL-1', 'category_ids' => $this->unmapped.'|'.$this->mapped];
                // No category resolvable → required 'cat' skips, optional 'gcat' omits.
                yield ['sku' => 'KL-2', 'category_ids' => $this->unmapped];
                // Explicit mapping value present → resolution must NOT overwrite it.
                yield ['sku' => 'KL-3', 'category_ids' => $this->mapped, 'category_path' => 'Dom > Ogród'];
            }
        };

        $resolver = new class($catMapped) implements ChannelCategoryExternalCodeResolverInterface {
            /** @var list<array{0: string, 1: list<string>}> */
            public array $calls = [];

            public function __construct(private readonly string $mapped)
            {
            }

            public function resolveExternalCodes(Uuid $channelId, array $masterCategoryIds): array
            {
                $this->calls[] = [$channelId->toRfc4122(), $masterCategoryIds];

                return \in_array($this->mapped, $masterCategoryIds, true) ? [$this->mapped => '123'] : [];
            }
        };

        $logRepo = new class implements FeedRunLogRepositoryInterface {
            /** @var list<FeedRunLog> */
            public array $lines = [];

            public function save(FeedRunLog $log): void
            {
                $this->lines[] = $log;
            }

            public function saveMany(array $logs): void
            {
                foreach ($logs as $log) {
                    $this->lines[] = $log;
                }
            }

            public function findByRun(Uuid $feedRunId): array
            {
                return [];
            }

            public function findPageByRun(Uuid $feedRunId, ?string $level, ?Uuid $cursor, int $limit): array
            {
                return [];
            }
        };

        $runRepo = new class implements FeedRunRepositoryInterface {
            public function save(FeedRun $run): void
            {
            }

            public function findById(Uuid $id): ?FeedRun
            {
                return null;
            }

            public function findByFeedProfile(Uuid $feedProfileId, int $limit = 50): array
            {
                return [];
            }

            public function findPage(?Uuid $feedProfileId, ?string $health, ?Uuid $cursor, int $limit): array
            {
                return [];
            }

            public function kpi24h(\App\Shared\Domain\Tenant $tenant, DateTimeImmutable $now): array
            {
                return ['regenerations_24h' => 0, 'skipped_24h' => 0, 'errors_24h' => 0, 'last_error' => null];
            }
        };

        $profile = new FeedProfile(
            code: 'ceneo_pl',
            name: 'Ceneo PL',
            templateKind: FeedTemplateKind::Custom,
            objectTypeId: Uuid::v7(),
            descriptor: [
                'root' => ['element' => 'offers'],
                'item' => [
                    'element' => 'o',
                    'slots' => [
                        ['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text'],
                        ['slot' => 'cat', 'node' => 'element', 'required' => true, 'fmt' => 'category'],
                        ['slot' => 'gcat', 'node' => 'element', 'fmt' => 'category'],
                    ],
                ],
            ],
            fieldMappings: [
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                // Explicit mapping for 'cat' — its value (when present) wins.
                ['slot' => 'cat', 'source' => ['kind' => 'attribute', 'ref' => 'category_path']],
            ],
            channelId: $channelId,
        );

        try {
            $generator = new FeedGenerator($source, new FeedItemMapper(new FeedTransformApplier()), new FeedRequiredValidator(), $runRepo, $logRepo, $resolver);
            $run = $generator->generate($profile, $path, FeedRunTrigger::Manual);

            self::assertSame(2, $run->getItemCount(), 'KL-1 resolved + KL-3 explicit; KL-2 skipped');
            self::assertSame(1, $run->getSkippedCount());

            $scope = $source->seenScope;
            self::assertNotNull($scope);
            self::assertContains('category_ids', $scope->attributeCodes, 'generator requests the built-in only when category slots exist');

            $dom = new DOMDocument();
            self::assertNotFalse($dom->loadXML((string) file_get_contents($path)));
            $cats = $dom->getElementsByTagName('cat');
            self::assertSame(2, $cats->length);
            self::assertSame('123', $cats->item(0)?->textContent, 'KL-1: resolved external_code');
            self::assertSame('Dom > Ogród', $cats->item(1)?->textContent, 'KL-3: explicit mapping wins');
            self::assertSame(2, $dom->getElementsByTagName('gcat')->length, 'optional gcat filled where resolvable');

            // KL-2: required 'cat' violation (validator) + optional 'gcat' omission (generator).
            $slots = array_map(static fn (FeedRunLog $line): ?string => $line->getSlot(), $logRepo->lines);
            self::assertContains('cat', $slots);
            self::assertContains('gcat', $slots);

            // Memoisation: the port is hit once per distinct category id.
            $queried = array_merge(...array_map(static fn (array $call): array => $call[1], $resolver->calls));
            self::assertSame(\count(array_unique($queried)), \count($queried), 'no category id is resolved twice');
        } finally {
            @unlink($path);
        }
    }

    private function stubResolver(): ChannelCategoryExternalCodeResolverInterface
    {
        return new class implements ChannelCategoryExternalCodeResolverInterface {
            public function resolveExternalCodes(Uuid $channelId, array $masterCategoryIds): array
            {
                return [];
            }
        };
    }
}
