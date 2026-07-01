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

    public function findByTenantAndCode(Tenant $tenant, string $code): ?FeedProfile;

    /**
     * @return list<FeedProfile>
     */
    public function findByTenant(Tenant $tenant): array;
}
