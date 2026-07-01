<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Generator;

use App\Export\Contracts\FeedProductScope;
use App\Export\Contracts\FeedProductValues;
use App\Export\Feed\Application\Async\FeedCancelledException;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Entity\FeedRunLog;
use App\Export\Feed\Domain\Enum\FeedRunLogLevel;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Enum\FeedValidationPolicy;
use App\Export\Feed\Domain\Generator\FeedRequiredValidator;
use App\Export\Feed\Domain\Mapping\FeedFieldMapping;
use App\Export\Feed\Domain\Mapping\FeedItemMapper;
use App\Export\Feed\Domain\Repository\FeedRunLogRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Export\Feed\Infrastructure\Writer\XmlFeedWriter;
use App\Export\Infrastructure\Writer\XmlWriterCore;
use Closure;
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

    /** Progress-callback cadence — aligned with the value source's EM-clear interval. */
    private const int PROGRESS_EVERY = 200;

    public function __construct(
        private readonly FeedProductValues $values,
        private readonly FeedItemMapper $mapper,
        private readonly FeedRequiredValidator $validator,
        private readonly FeedRunRepositoryInterface $runs,
        private readonly FeedRunLogRepositoryInterface $logs,
    ) {
    }

    /**
     * @param FeedRun|null            $run     pre-created run to drive (async worker, XMLF-P4-02);
     *                                         null creates one inline (sync path)
     * @param Closure(int): void|null $onChunk invoked with the processed-row count every
     *                                         PROGRESS_EVERY rows; may throw
     *                                         {@see FeedCancelledException} to stop gracefully
     */
    public function generate(FeedProfile $profile, string $targetPath, FeedRunTrigger $trigger, ?FeedRun $run = null, ?Closure $onChunk = null): FeedRun
    {
        $run ??= new FeedRun($profile->getId(), $trigger);
        $run->markRunning();
        $this->runs->save($run);

        $descriptor = FeedDescriptor::fromArray($profile->getDescriptor());
        $mappings = FeedFieldMapping::listFromArray($profile->getFieldMappings());
        $context = $this->context($profile);
        $scope = $this->scope($profile, $mappings);
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
            foreach ($this->values->forScope($scope) as $attributes) {
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
                        $this->tickProgress($onChunk, $items + $skipped);
                        continue;
                    }
                }

                $writer->writeItem($item);
                ++$items;
                $buffer = $this->maybeFlush($buffer);
                $this->tickProgress($onChunk, $items + $skipped);
            }
            $writer->finish();
        } catch (FeedCancelledException $cancelled) {
            // The cancel endpoint already persisted the terminal `cancelled`
            // status — flush the health trail collected so far and let the
            // async handler publish the final state. No markError: cancelling
            // is a user decision, not a failure.
            $this->logs->saveMany($buffer);

            throw $cancelled;
        } catch (Throwable $error) {
            $this->logs->saveMany($buffer);
            // The value source clears the EM between chunks (memory-flat 50k),
            // which detaches $run — reload a managed instance before the
            // terminal transition so save() issues an UPDATE, not a duplicate.
            $run = $this->reload($run);
            $run->markError($error->getMessage());
            $this->runs->save($run);

            throw $error;
        }

        $this->logs->saveMany($buffer);

        $size = is_file($targetPath) ? (int) filesize($targetPath) : 0;
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $run = $this->reload($run);
        $run->markDone($items, $skipped, $warnings, $targetPath, $size, $durationMs);
        $this->runs->save($run);

        return $run;
    }

    /**
     * Reload a managed FeedRun after the value source's mid-run EM clears
     * detached it (falls back to the detached instance if the row is gone).
     */
    private function reload(FeedRun $run): FeedRun
    {
        return $this->runs->findById($run->getId()) ?? $run;
    }

    /**
     * @param Closure(int): void|null $onChunk
     */
    private function tickProgress(?Closure $onChunk, int $processed): void
    {
        if (null !== $onChunk && $processed > 0 && 0 === $processed % self::PROGRESS_EVERY) {
            $onChunk($processed);
        }
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
     * @param list<FeedFieldMapping> $mappings
     */
    private function scope(FeedProfile $profile, array $mappings): FeedProductScope
    {
        $codes = ['sku' => true]; // always needed for the FeedRunLog object_sku
        foreach ($mappings as $mapping) {
            if ('attribute' === $mapping->sourceKind && null !== $mapping->sourceRef) {
                $codes[$mapping->sourceRef] = true;
            }
        }

        return new FeedProductScope(
            objectTypeId: $profile->getObjectTypeId(),
            attributeCodes: array_keys($codes),
            locale: $profile->getLocale(),
            channel: $profile->getPublicationChannel(),
            filter: $profile->getFilter(),
        );
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
