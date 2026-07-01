<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Contracts\FeedProductScope;
use App\Export\Contracts\FeedProductValues;
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
        };

        try {
            $generator = new FeedGenerator($source, new FeedItemMapper(new FeedTransformApplier()), new FeedRequiredValidator(), $runRepo, $logRepo);
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
}
