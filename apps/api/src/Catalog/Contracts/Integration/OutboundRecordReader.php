<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Integration;

use Symfony\Component\Uid\Uuid;

/**
 * Cross-BC seam for the API Configurator outbound sync (APIC-P3-06, ADR-0022).
 *
 * Lets the Integration context read a tenant's catalog objects serialised for
 * push — reusing the Export engine's cell serializer — without importing any
 * Catalog or Export Application/Domain type. The implementation iterates the
 * ObjectType's objects (memory-bounded) and yields each as an
 * {@see OutboundRecord} of `attributeCode => serialized value` for the requested
 * codes.
 */
interface OutboundRecordReader
{
    /**
     * @param list<string>              $codes  the attribute codes to serialise per object
     * @param array<string, mixed>|null $filter #2549 — a FilterDsl snapshot scoping which
     *                                          objects are emitted (null/empty = all)
     *
     * @return iterable<OutboundRecord>
     */
    public function read(Uuid $objectTypeId, array $codes, ?array $filter = null): iterable;
}
