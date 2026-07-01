<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Generator;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Entity\FeedRunLog;
use App\Export\Feed\Domain\Enum\FeedRunLogLevel;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Enum\FeedValidationPolicy;
use App\Export\Feed\Domain\Generator\FeedProductValues;
use App\Export\Feed\Domain\Generator\FeedRequiredValidator;
use App\Export\Feed\Domain\Mapping\FeedFieldMapping;
use App\Export\Feed\Domain\Mapping\FeedItemMapper;
use App\Export\Feed\Domain\Repository\FeedRunLogRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Export\Feed\Infrastructure\Writer\XmlFeedWriter;
use App\Export\Infrastructure\Writer\XmlWriterCore;
use Throwable;

/**
 * Orchestrates one feed regeneration (ADR-0023 §6.3, XMLF-P2-04): streams the
 * product value maps ({@see FeedProductValues}, memory-bounded), applies the
 * feed's mappings + transforms ({@see FeedItemMapper}), validates required
 * fields ({@see FeedRequiredValidator}) under the feed's skip policy, serializes
 * through the descriptor-driven {@see XmlFeedWriter} to $targetPath, and records
 * the {@see FeedRun} + per-product {@see FeedRunLog} health trail.
 *
 * Delivery (MinIO cache + public URL) is XMLF-P3-05; scope/filter wiring of the
 * value source is XMLF-P3-02.
 */
final class FeedGenerator
{
    private const int LOG_FLUSH_EVERY = 500;

    public function __construct(
        private readonly FeedProductValues $values,
        private readonly FeedItemMapper $mapper,
        private readonly FeedRequiredValidator $validator,
        private readonly FeedRunRepositoryInterface $runs,
        private readonly FeedRunLogRepositoryInterface $logs,
    ) {
    }

    public function generate(FeedProfile $profile, string $targetPath, FeedRunTrigger $trigger): FeedRun
    {
        $run = new FeedRun($profile->getId(), $trigger);
        $run->markRunning();
        $this->runs->save($run);

        $descriptor = FeedDescriptor::fromArray($profile->getDescriptor());
        $mappings = FeedFieldMapping::listFromArray($profile->getFieldMappings());
        $context = $this->context($profile);
        $skip = FeedValidationPolicy::SkipInvalid === $profile->getValidationPolicy();

        $xml = XmlWriterCore::toUri($targetPath);
        $writer = new XmlFeedWriter($xml, $descriptor, $context);
        $writer->begin();

        $items = 0;
        $skipped = 0;
        $warnings = 0;
        /** @var list<FeedRunLog> $buffer */
        $buffer = [];
        $startedAt = hrtime(true);

        try {
            foreach ($this->values->forProfile($profile) as $attributes) {
                $item = $this->mapper->map($mappings, $attributes, $context);
                $violations = $this->validator->check($descriptor, $item);
                $sku = $attributes['sku'] ?? null;

                if ([] !== $violations) {
                    foreach ($violations as $violation) {
                        $buffer[] = new FeedRunLog($run->getId(), FeedRunLogLevel::Warning, $violation['message'], $sku, $violation['slot']);
                    }
                    $warnings += \count($violations);
                    if ($skip) {
                        ++$skipped;
                        $buffer = $this->maybeFlush($buffer);
                        continue;
                    }
                }

                $writer->writeItem($item);
                ++$items;
                $buffer = $this->maybeFlush($buffer);
            }
            $writer->finish();
        } catch (Throwable $error) {
            $this->logs->saveMany($buffer);
            $run->markError($error->getMessage());
            $this->runs->save($run);

            throw $error;
        }

        $this->logs->saveMany($buffer);

        $size = is_file($targetPath) ? (int) filesize($targetPath) : 0;
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $run->markDone($items, $skipped, $warnings, $targetPath, $size, $durationMs);
        $this->runs->save($run);

        return $run;
    }

    /**
     * @param list<FeedRunLog> $buffer
     *
     * @return list<FeedRunLog>
     */
    private function maybeFlush(array $buffer): array
    {
        if (\count($buffer) >= self::LOG_FLUSH_EVERY) {
            $this->logs->saveMany($buffer);

            return [];
        }

        return $buffer;
    }

    /**
     * @return array<string, string>
     */
    private function context(FeedProfile $profile): array
    {
        $delivery = $profile->getDelivery();
        $storeUrl = \is_string($delivery['store_url'] ?? null) ? $delivery['store_url'] : '';

        return [
            'currency' => $profile->getCurrency() ?? '',
            'store_url' => $storeUrl,
            'feed_name' => $profile->getName(),
        ];
    }
}
