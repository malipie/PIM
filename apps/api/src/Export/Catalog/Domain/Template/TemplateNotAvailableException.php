<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Template;

use RuntimeException;

/**
 * Raised when a template kind is requested whose archetype is not yet shipped
 * (ADR-0027, CPDF-P2-01). All three built-in archetypes ship as of CPDF-P6-02;
 * kept for future template kinds.
 */
final class TemplateNotAvailableException extends RuntimeException
{
}
