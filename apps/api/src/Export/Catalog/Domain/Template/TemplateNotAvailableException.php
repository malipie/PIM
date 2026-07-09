<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Template;

use RuntimeException;

/**
 * Raised when a template kind is requested whose archetype is not yet shipped
 * (ADR-0027, CPDF-P2-01): the grid archetype arrives in CPDF-P6-02.
 */
final class TemplateNotAvailableException extends RuntimeException
{
}
