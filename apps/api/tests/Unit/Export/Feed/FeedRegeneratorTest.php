<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Channel\Contracts\ChannelCategoryExternalCodeResolverInterface;
use App\Export\Contracts\FeedProductScope;
use App\Export\Contracts\FeedProductValues;
use App\Export\Feed\Application\Delivery\FeedCacheStorage;
use App\Export\Feed\Application\Generator\FeedGenerator;
use App\Export\Feed\Application\Generator\FeedRegenerator;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Entity\FeedRunLog;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Generator\FeedRequiredValidator;
use App\Export\Feed\Domain\Mapping\FeedItemMapper;
use App\Export\Feed\Domain\Mapping\FeedTransformApplier;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunLogRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P4-01 — regeneration orchestrator: generate → cache upload → record
 * cache pointer on the profile.
 */
final class FeedRegeneratorTest extends TestCase
{
    private function profile(): FeedProfile
    {
        return new FeedProfile(
            code: 'google_pl',
            name: 'Google PL',
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

    private function generator(FeedProductValues $source): FeedGenerator
    {
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

            public function kpi24h(Tenant $tenant, DateTimeImmutable $now): array
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

        $externalCodes = new class implements ChannelCategoryExternalCodeResolverInterface {
            public function resolveExternalCodes(Uuid $channelId, array $masterCategoryIds): array
            {
                return [];
            }
        };

        return new FeedGenerator($source, new FeedItemMapper(new FeedTransformApplier()), new FeedRequiredValidator(), $runRepo, $logRepo, $externalCodes);
    }

    private function repo(FeedProfile $profile): FeedProfileRepositoryInterface
    {
        return new class($profile) implements FeedProfileRepositoryInterface {
            public function __construct(private ?FeedProfile $known)
            {
            }

            public function save(FeedProfile $profile): void
            {
            }

            public function remove(FeedProfile $profile): void
            {
            }

            public function findById(Uuid $id): ?FeedProfile
            {
                return $this->known;
            }

            public function findByTokenHash(string $tokenHash): ?FeedProfile
            {
                return null;
            }

            public function findByTenantAndCode(Tenant $tenant, string $code): ?FeedProfile
            {
                return null;
            }

            public function findByTenant(Tenant $tenant): array
            {
                return [];
            }
        };
    }

    private function tenantContext(): TenantContext
    {
        $context = new TenantContext();
        $context->set(new Tenant('demo', 'Demo'));

        return $context;
    }

    #[Test]
    public function regenerateUploadsCacheAndRecordsPointer(): void
    {
        $source = new class implements FeedProductValues {
            public function forScope(FeedProductScope $scope): iterable
            {
                yield ['sku' => 'KL-1', 'name' => 'Wkręt'];
                yield ['sku' => 'KL-2', 'name' => 'Kątownik'];
            }
        };

        $cache = new class implements FeedCacheStorage {
            /** @var list<string> */
            public array $keys = [];
            public string $content = '';

            public function put(string $key, string $localPath): void
            {
                $this->keys[] = $key;
                $this->content = (string) file_get_contents($localPath);
            }

            public function exists(string $key): bool
            {
                return \in_array($key, $this->keys, true);
            }

            public function read(string $key)
            {
                $stream = fopen('php://memory', 'r+');
                \assert(false !== $stream);
                fwrite($stream, $this->content);
                rewind($stream);

                return $stream;
            }
        };

        $profile = $this->profile();
        $repo = $this->repo($profile);

        $run = new FeedRegenerator($this->generator($source), $cache, $repo, $this->tenantContext())
            ->regenerate($profile, FeedRunTrigger::Manual);

        self::assertSame(FeedRunStatus::Done, $run->getStatus());
        self::assertSame(2, $run->getItemCount());

        self::assertCount(1, $cache->keys);
        self::assertStringStartsWith('feeds/', $cache->keys[0]);
        self::assertStringEndsWith($profile->getId()->toRfc4122().'.xml', $cache->keys[0]);

        self::assertSame($cache->keys[0], $profile->getCachedFilePath());
        self::assertSame(2, $profile->getCachedItemCount());
        self::assertNotNull($profile->getCachedAt());

        $dom = new DOMDocument();
        self::assertNotFalse($dom->loadXML($cache->content));
        self::assertSame(2, $dom->getElementsByTagName('product')->length);
    }

    #[Test]
    public function regenerateRequiresTenantContext(): void
    {
        $source = new class implements FeedProductValues {
            public function forScope(FeedProductScope $scope): iterable
            {
                yield ['sku' => 'X', 'name' => 'Y'];
            }
        };
        $cache = new class implements FeedCacheStorage {
            public function put(string $key, string $localPath): void
            {
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function read(string $key)
            {
                $stream = fopen('php://memory', 'r');
                \assert(false !== $stream);

                return $stream;
            }
        };
        $profile = $this->profile();
        $repo = $this->repo($profile);

        $this->expectException(RuntimeException::class);
        new FeedRegenerator($this->generator($source), $cache, $repo, new TenantContext())
            ->regenerate($profile, FeedRunTrigger::Manual);
    }
}
