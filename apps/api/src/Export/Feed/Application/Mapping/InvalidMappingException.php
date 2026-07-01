<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Mapping;

use RuntimeException;

/**
 * Raised by {@see FeedMappingService::applyUpdate()} when a PUT mapping payload
 * is malformed (unknown slot, unknown source kind, unknown attribute code…).
 * The controller (XMLF-P3-01) translates it to an RFC 7807 400.
 */
final class InvalidMappingException extends RuntimeException
{
}
