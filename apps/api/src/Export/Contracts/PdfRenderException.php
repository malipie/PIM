<?php

declare(strict_types=1);

namespace App\Export\Contracts;

use RuntimeException;

/**
 * Thrown when a {@see PdfRenderer} cannot turn HTML into a valid PDF
 * (ADR-0027, CPDF-P0-03).
 */
final class PdfRenderException extends RuntimeException
{
}
