<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Descriptor;

use InvalidArgumentException;

/**
 * Thrown when a feed descriptor JSONB payload is structurally invalid
 * (unknown node kind, illegal XML name, attribute without a parent, an
 * `enum` format without values, a `requiredOneOf` referencing a missing slot).
 * The canonical shape guard (XMLF-P2-01).
 */
final class InvalidDescriptorException extends InvalidArgumentException
{
}
