<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Enum;

/**
 * Feed template kind (ADR-0023, XMLF-P1-01). The three predefined kinds seed a
 * built-in descriptor (XMLF-P2-02); `custom` starts blank or clones a predef
 * and is fully structure-editable (XMLF-P2-07).
 */
enum FeedTemplateKind: string
{
    case GoogleShopping = 'google_shopping';
    case Ceneo = 'ceneo';
    case Meta = 'meta';
    case Custom = 'custom';

    public function isBuiltIn(): bool
    {
        return self::Custom !== $this;
    }
}
