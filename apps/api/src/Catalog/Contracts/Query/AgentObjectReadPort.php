<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

/**
 * Agent-safe item read: one tenant-scoped object, filtered with the
 * initiating user's per-attribute permissions and the requested view scope.
 */
interface AgentObjectReadPort
{
    /**
     * @param list<string>|null $attributeCodes null = bounded non-empty projection
     *
     * @return array<string, mixed>|null null deliberately covers unknown,
     *                                   foreign-tenant and wrong-type objects
     */
    public function read(
        Tenant $tenant,
        Uuid $userId,
        ?Uuid $objectId,
        ?string $code,
        ?string $objectTypeCode,
        ?array $attributeCodes,
        ?string $locale,
        ?string $channel,
    ): ?array;
}
