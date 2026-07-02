<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Search\Contracts\CatalogQueryPort;
use App\Search\Contracts\CatalogQueryResult;

/**
 * Test-only recorder exposing the last call arguments.
 */
final class RecordingCatalogQueryPort implements CatalogQueryPort
{
    public string $lastKind = '';

    /** @var array<string, mixed> */
    public array $lastFilterDsl = [];

    public int $lastPerPage = 0;

    public function __construct(private readonly CatalogQueryResult $result)
    {
    }

    public function search(string $kind, string $query = '', array $filterDsl = [], int $page = 1, int $perPage = 20): CatalogQueryResult
    {
        $this->lastKind = $kind;
        $this->lastFilterDsl = $filterDsl;
        $this->lastPerPage = $perPage;

        return $this->result;
    }
}
