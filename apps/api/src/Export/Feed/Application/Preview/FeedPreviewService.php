<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Preview;

use App\Export\Contracts\FeedProductScope;
use App\Export\Contracts\FeedProductValues;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Generator\FeedRequiredValidator;
use App\Export\Feed\Domain\Mapping\FeedFieldMapping;
use App\Export\Feed\Domain\Mapping\FeedItemMapper;
use App\Export\Feed\Infrastructure\Writer\XmlFeedWriter;
use App\Export\Infrastructure\Writer\XmlWriterCore;

/**
 * Feed preview (ADR-0023 §6.8, XMLF-P4-04) — renders the first N products
 * through the exact map → validate → serialize pipeline the generator uses
 * (XMLF-P2-04), but in-memory: no FeedRun, no cache upload, no persistence.
 *
 * It powers the wizard's "Podgląd" step for both a draft (unsaved descriptor +
 * mappings) and a saved feed, returning the sample XML plus a health report so
 * the operator sees exactly what the feed will emit — including which required
 * slots are missing — before committing to a full regeneration.
 */
final class FeedPreviewService
{
    public const int DEFAULT_LIMIT = 5;
    private const int MAX_LIMIT = 25;

    public function __construct(
        private readonly FeedProductValues $values,
        private readonly FeedItemMapper $mapper,
        private readonly FeedRequiredValidator $validator,
    ) {
    }

    /**
     * @param list<FeedFieldMapping> $mappings
     * @param array<string, string>  $context
     *
     * @return array{sample_count: int, xml: string, health: list<array{sku: string|null, slot: string, level: string, message: string}>}
     */
    public function preview(FeedDescriptor $descriptor, array $mappings, FeedProductScope $scope, array $context, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $xml = XmlWriterCore::toMemory();
        $writer = new XmlFeedWriter($xml, $descriptor, $context);
        $writer->begin();

        $health = [];
        $count = 0;
        foreach ($this->values->forScope($scope) as $attributes) {
            if ($count >= $limit) {
                break;
            }
            $item = $this->mapper->map($mappings, $attributes, $context);
            $sku = \is_string($attributes['sku'] ?? null) ? $attributes['sku'] : null;
            foreach ($this->validator->check($descriptor, $item) as $violation) {
                $health[] = [
                    'sku' => $sku,
                    'slot' => $violation['slot'],
                    'level' => 'warning',
                    'message' => $violation['message'],
                ];
            }
            // The preview shows what WOULD be emitted (warnings flagged
            // separately), so invalid samples are still written.
            $writer->writeItem($item);
            ++$count;
        }
        $writer->finish();

        return [
            'sample_count' => $count,
            'xml' => $xml->outputMemory(),
            'health' => $health,
        ];
    }
}
