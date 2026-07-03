<?php

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use DateTimeImmutable;

interface RefreshTokenRepositoryInterface
{
    public function findById(\Symfony\Component\Uid\Uuid $id): ?\App\Identity\Domain\Entity\RefreshToken;

    public function findByHash(string $tokenHash): ?\App\Identity\Domain\Entity\RefreshToken;

    public function revokeFamily(\Symfony\Component\Uid\Uuid $familyId, DateTimeImmutable $when): void;

    public function revokeAllForUser(\Symfony\Component\Uid\Uuid $userId, DateTimeImmutable $when): void;

    public function purgeExpired(DateTimeImmutable $cutoff): int;

    public function save(\App\Identity\Domain\Entity\RefreshToken $entity): void;

    public function remove(\App\Identity\Domain\Entity\RefreshToken $entity): void;
}
