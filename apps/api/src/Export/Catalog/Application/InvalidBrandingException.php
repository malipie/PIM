<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application;

use RuntimeException;

/**
 * Raised by {@see CatalogBrandingGuard} when a profile's branding or its
 * field mappings are malformed (ADR-0027, CPDF-P2-01).
 */
final class InvalidBrandingException extends RuntimeException
{
}
