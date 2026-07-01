<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Repository;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

interface FeedProfileRepositoryInterface
{
    public function save(FeedProfile $profile): void;

    public function remove(FeedProfile $profile): void;

    public function findById(Uuid $id): ?FeedProfile;

    /**
     * Resolve a feed by its stored token HMAC (XMLF-P3-05 public URL). The
     * caller MUST have already established the tenant context / RLS scope from
     * the URL, so this only returns a hit inside the current tenant — a token
     * from tenant A can never resolve a feed of tenant B.
     */
    public function findByTokenHash(string $tokenHash): ?FeedProfile;

    public function findByTenantAndCode(Tenant $tenant, string $code): ?FeedProfile;

    /**
     * @return list<FeedProfile>
     */
    public function findByTenant(Tenant $tenant): array;
}
