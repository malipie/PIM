<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Contracts\Directory\UserDirectoryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

use const MB_CASE_TITLE;

/**
 * WFL redesign (#2517) — resolves user ids to a display label. The users
 * table only carries `email` (dedicated name columns are deferred, see
 * UserListResponseBuilder), so the label is derived from the email the
 * same way the users list does, keeping both surfaces consistent.
 */
final readonly class SqlUserDirectory implements UserDirectoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function resolve(array $userIds): array
    {
        $ids = \array_values(\array_unique(\array_filter($userIds, static fn (string $id): bool => '' !== $id)));
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: string, email: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, email FROM users WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $out = [];
        foreach ($rows as $row) {
            $email = $row['email'];
            $out[$row['id']] = ['name' => $this->deriveName($email), 'email' => $email];
        }

        return $out;
    }

    private function deriveName(string $email): string
    {
        $localPart = \explode('@', $email, 2)[0];
        $normalised = \preg_replace('/[._-]+/', ' ', $localPart);
        if (null === $normalised || '' === \trim($normalised)) {
            return $email;
        }

        return \mb_convert_case(\trim($normalised), MB_CASE_TITLE, 'UTF-8');
    }
}
