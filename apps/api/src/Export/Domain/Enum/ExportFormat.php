<?php

declare(strict_types=1);

namespace App\Export\Domain\Enum;

/**
 * Output formats. XLSX is the round-trip target with Excel; CSV is the lighter
 * alternative for Marcin's Python pipeline. XML (XMLF-P0-05, ADR-0023) is the
 * ad-hoc export mode 2 — a generic `<products><product>` dump serialized via
 * {@see \App\Export\Infrastructure\Writer\GenericXmlWriter}; the persistent
 * feed path (Google/Ceneo/Meta) lives in the Export/Feed sub-area.
 */
enum ExportFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';
    case Xml = 'xml';

    public function mimeType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
            self::Xml => 'application/xml',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
