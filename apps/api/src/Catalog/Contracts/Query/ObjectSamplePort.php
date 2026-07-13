<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

/**
 * #2550 — cross-BC read of a scoped SAMPLE of catalog objects for the live
 * profile preview (ApiConfigurator). Applies the profile's canonical scope
 * (ObjectType list + status/enabled/completeness query-param filters — the
 * same axes {@see \App\Catalog\Infrastructure\ApiPlatform\State\ProfileScopeApplier}
 * enforces on the live API) and returns a bounded set of denormalised rows,
 * so the admin can preview exactly what a partner integration would receive
 * without minting an API key.
 */
interface ObjectSamplePort
{
    /**
     * @param list<string>         $objectTypeIds    ObjectType uuids the profile exposes ([] = any)
     * @param array<string, mixed> $canonicalFilters query-param dict (status / enabled / completeness)
     * @param int                  $limit            max rows to return (clamped)
     *
     * @return list<ObjectSample>
     */
    public function sample(array $objectTypeIds, array $canonicalFilters, int $limit): array;
}
