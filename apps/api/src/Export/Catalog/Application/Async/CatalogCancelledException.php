<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application\Async;

use RuntimeException;

/**
 * CPDF-P3-01 — thrown by the per-chunk progress callback when the persisted
 * {@see \App\Export\Catalog\Domain\Entity\CatalogRun} status flips to
 * `cancelled` (the cancel endpoint writes it; the worker polls it between
 * chunks).
 *
 * Mirrors {@see \App\Export\Feed\Application\Async\FeedCancelledException}: the
 * regenerator lets it pass through and records `cancelled` on the run WITHOUT
 * marking it as error, and the handler treats it as a graceful stop, not a
 * failure.
 */
final class CatalogCancelledException extends RuntimeException
{
}
