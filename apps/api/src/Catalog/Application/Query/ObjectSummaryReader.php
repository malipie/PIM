<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Query\GetObjectSummary\GetObjectSummaryHandler;
use App\Catalog\Application\Query\GetObjectSummary\GetObjectSummaryQuery;
use App\Catalog\Contracts\Query\ObjectSummaryPort;
use Symfony\Component\Uid\Uuid;

/**
 * WFL redesign (#2518) — batch adapter over the single-object summary
 * handler. The task list resolves a page's worth of object ids at once;
 * per-id resolution reuses the existing projection so the label logic
 * stays in one place. Unknown / cross-tenant ids are simply omitted
 * (RLS + the handler's null-on-missing keep isolation intact).
 */
final readonly class ObjectSummaryReader implements ObjectSummaryPort
{
    public function __construct(private GetObjectSummaryHandler $handler)
    {
    }

    public function summariesByIds(array $objectIds): array
    {
        $out = [];
        foreach (\array_unique($objectIds) as $rawId) {
            if (!Uuid::isValid($rawId)) {
                continue;
            }
            $summary = ($this->handler)(new GetObjectSummaryQuery(Uuid::fromString($rawId)));
            if (null !== $summary) {
                $out[$rawId] = $summary;
            }
        }

        return $out;
    }
}
