<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** Test-only DBAL logger that retains SQL shapes, never parameters. */
final class SqlQueryCounter extends AbstractLogger
{
    private bool $enabled = false;

    /** @var list<string> */
    private array $queries = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $sql = $context['sql'] ?? null;
        if (\is_string($sql)) {
            $this->queries[] = $sql;
        }
    }

    public function start(): void
    {
        $this->queries = [];
        $this->enabled = true;
    }

    public function stop(): void
    {
        $this->enabled = false;
        $this->queries = [];
    }

    public function countMatching(string $pattern): int
    {
        return \count($this->matching($pattern));
    }

    /** @return list<string> */
    public function matching(string $pattern): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => 1 === preg_match($pattern, $sql),
        ));
    }
}
