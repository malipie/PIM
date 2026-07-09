<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Template;

use RuntimeException;

/**
 * Raised when a template kind is requested whose archetype is not yet shipped
 * (ADR-0027, CPDF-P2-01): grid and pricelist archetypes arrive in M6.
 */
final class TemplateNotAvailableException extends RuntimeException
{
}
