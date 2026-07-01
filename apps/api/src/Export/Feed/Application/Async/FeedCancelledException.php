<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Async;

use RuntimeException;

/**
 * XMLF-P4-02 — thrown by the per-chunk progress callback when the persisted
 * {@see \App\Export\Feed\Domain\Entity\FeedRun} status flips to `cancelled`
 * (the cancel endpoint writes it; the worker polls it between chunks).
 *
 * Mirrors {@see \App\Export\Application\Async\ExportCancelledException}: the
 * generator lets it pass through WITHOUT marking the run as error (the
 * terminal state is already persisted), and the handler treats it as a
 * graceful stop, not a failure.
 */
final class FeedCancelledException extends RuntimeException
{
}
