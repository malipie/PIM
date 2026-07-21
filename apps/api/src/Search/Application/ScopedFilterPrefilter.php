<?php

declare(strict_types=1);

namespace App\Search\Application;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Shared\Application\TenantContext;
use App\Shared\Infrastructure\Meilisearch\MeiliFilterLiteral;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * #2673 — SQL prefilter for filter DSL documents carrying a value-context
 * `scope: {channel?, locale?}`.
 *
 * Scoped attribute values live only in `object_values` (#1148 keeps the
 * Meilisearch document global-only), so a scoped DSL cannot compile to a
 * Meili filter. Instead the whole document is evaluated in Postgres via
 * `FilterDslResolver::toCountSql()` and the matching ids are handed to
 * Meilisearch as an `id IN [...]` expression — the phrase, facets, sort and
 * pagination stay in Meili untouched.
 *
 * The id list is capped at {@see self::CAP}; above it the result is
 * truncated and flagged (`scopeTruncated`) so the FE can tell the operator
 * the hit list is approximate. Scoped filters are selective by nature —
 * the cap is a guard rail, not an expected path.
 */
final readonly class ScopedFilterPrefilter
{
    public const int CAP = 10_000;

    /** Never-matching Meili expression — ids are UUIDs, this literal is not. */
    private const string EMPTY_MATCH = "id = 'scope-empty-match'";

    public function __construct(
        private FilterDslResolver $filterDslResolver,
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * @param array<string, mixed> $dsl
     *
     * @return array{0: string, 1: bool} [meili expression, truncated]
     */
    public function compile(array $dsl): array
    {
        // Loud validation: an unknown channel/locale in the scope must 400
        // with a clear message, not degrade into an empty hit list.
        $this->filterDslResolver->validate($dsl);
        $where = $this->filterDslResolver->toCountSql($dsl);
        if (null === $where) {
            throw new BadRequestHttpException('Invalid filter DSL.');
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('Scoped filters require a tenant context.');
        }

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT co.id FROM objects co WHERE co.tenant_id = :tenant AND ('.$where.') LIMIT '.(self::CAP + 1),
            ['tenant' => $tenant->getId()->toRfc4122()],
        );

        $truncated = \count($ids) > self::CAP;
        if ($truncated) {
            $ids = \array_slice($ids, 0, self::CAP);
        }
        if ([] === $ids) {
            return [self::EMPTY_MATCH, false];
        }

        $list = implode(', ', array_map(
            static fn (string $id): string => MeiliFilterLiteral::quote($id),
            $ids,
        ));

        return ['id IN ['.$list.']', $truncated];
    }
}
