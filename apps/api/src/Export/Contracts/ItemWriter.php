<?php

declare(strict_types=1);

namespace App\Export\Contracts;

/**
 * Associative item writer contract (ADR-0023, XMLF-P0-04).
 *
 * The existing {@see \App\Export\Infrastructure\Writer\RowWriter} is positional
 * (a flat list of strings) and cannot name elements — insufficient for XML,
 * where a column key becomes an element/attribute name. ItemWriter is the
 * parallel, key-aware contract: it consumes the same
 * `Generator<array<string,string>>` the {@see \App\Export\Application\Builder\ExportBuilder}
 * yields, but keeps CSV/XLSX untouched.
 *
 * Two implementations:
 *   - {@see \App\Export\Infrastructure\Writer\GenericXmlWriter} — ad-hoc export
 *     mode 2 (generic `<products><product>…` wrapper), lives in Export core;
 *   - `XmlFeedWriter` (XMLF-P2-05) — descriptor-driven feed serialization,
 *     lives in the `Export/Feed` sub-area.
 *
 * The output structure/config (root element, descriptor, column plan) is
 * implementation state passed via the constructor — never through this
 * contract — so both the generic and the feed writer share one lifecycle.
 */
interface ItemWriter
{
    /**
     * Open the document and any wrapping/header structure (generic root, or a
     * feed's `channel` + header nodes).
     */
    public function begin(): void;

    /**
     * Serialize one item.
     *
     * @param array<string, string> $item column-key => already-serialized value
     *                                    (as produced by ExportBuilder / ValueSerializer)
     */
    public function writeItem(array $item): void;

    /**
     * Close the wrapping structure and finalize the document.
     */
    public function finish(): void;
}
