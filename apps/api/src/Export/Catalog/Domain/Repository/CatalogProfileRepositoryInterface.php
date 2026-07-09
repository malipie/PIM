<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Repository;

use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

interface CatalogProfileRepositoryInterface
{
    public function save(CatalogProfile $profile): void;

    public function remove(CatalogProfile $profile): void;

    public function findById(Uuid $id): ?CatalogProfile;

    /**
     * Resolve a catalog by its stored token HMAC (CPDF public URL). The caller
     * MUST have already established the tenant context / RLS scope from the URL,
     * so this only returns a hit inside the current tenant — a token from tenant
     * A can never resolve a catalog of tenant B.
     */
    public function findByTokenHash(string $tokenHash): ?CatalogProfile;

    public function findByTenantAndCode(Tenant $tenant, string $code): ?CatalogProfile;

    /**
     * @return list<CatalogProfile>
     */
    public function findByTenant(Tenant $tenant): array;
}
