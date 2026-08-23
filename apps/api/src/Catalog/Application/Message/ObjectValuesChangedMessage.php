<?php

declare(strict_types=1);

namespace App\Catalog\Application\Message;

use App\Shared\Application\TenantAwareMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Asynchronous trigger to rebuild `attributes_indexed` + `completeness`
 * for a batch of `CatalogObject` rows that had their values changed by
 * a bulk path (CSV import, agent operation, etc). The tenant travels in
 * the payload: unlike an HTTP request, the worker has no authenticated
 * principal from which it could recover the tenant/RLS context (#2980).
 *
 * The bulk handler dispatches one message per chunk it processes,
 * carrying the affected object ids; {@see \App\Catalog\Application\Handler\RebuildAttributesIndexedHandler}
 * picks them up and rebuilds on a worker process so the request
 * thread can return immediately.
 *
 * @see \App\Catalog\Application\BulkContext
 */
final readonly class ObjectValuesChangedMessage implements TenantAwareMessage
{
    /**
     * @param list<string> $objectIds RFC-4122 UUID strings
     */
    public function __construct(
        public array $objectIds,
        public Uuid $tenantId,
    ) {
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
