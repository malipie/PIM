<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Contracts\ColumnMappingPort;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use LogicException;

/**
 * AGENT-P8-02 (#1984) — adapter behind Import\Contracts: runs the SAME
 * deterministic AutoMapper the wizard uses over the tenant's attribute
 * catalogue and hands the agent both the baseline suggestions and the
 * catalogue (codes + localised labels) - the model only fills the
 * "manual" gaps, never overrides an Auto match.
 */
final readonly class AgentColumnMappingAssist implements ColumnMappingPort
{
    public function __construct(
        private AutoMapper $autoMapper,
        private TenantContext $tenantContext,
        private Connection $connection,
    ) {
    }

    public function suggestMappings(array $headers, array $sampleRows): array
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot suggest mappings without a current tenant.');
        }

        // tenant-safe: explicit tenant_id predicate; RLS is the backstop.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.code, a.label::text AS label FROM attributes a WHERE a.tenant_id = :tenant ORDER BY a.code',
            ['tenant' => $tenant->getId()->toRfc4122()],
        );

        $codes = [];
        $labelsByCode = [];
        $catalogue = [];
        foreach ($rows as $row) {
            if (!\is_string($row['code'])) {
                continue;
            }
            $codes[] = $row['code'];
            $labels = [];
            if (\is_string($row['label'])) {
                $decoded = json_decode($row['label'], true);
                if (\is_array($decoded)) {
                    foreach ($decoded as $locale => $text) {
                        if (\is_string($locale) && \is_string($text)) {
                            $labels[$locale] = $text;
                        }
                    }
                }
            }
            $labelsByCode[$row['code']] = array_values($labels);
            $catalogue[] = ['code' => $row['code'], 'labels' => $labels];
        }

        // AutoMapper wants positional samples (list per row, aligned with
        // the header order) - the agent supplies header=>value objects.
        $positionalRows = [];
        foreach ($sampleRows as $row) {
            $positional = [];
            foreach ($headers as $header) {
                $positional[] = $row[$header] ?? null;
            }
            $positionalRows[] = $positional;
        }

        $suggestions = [];
        foreach ($this->autoMapper->map($codes, $headers, $positionalRows, $labelsByCode) as $suggestion) {
            $suggestions[] = [
                'header' => $suggestion->columnHeader,
                'suggested_code' => $suggestion->suggestedAttributeCode,
                'confidence' => $suggestion->confidence->value,
                'alternative_code' => $suggestion->alternativeAttributeCode,
                'samples' => $suggestion->sampleValues,
            ];
        }

        return ['suggestions' => $suggestions, 'attribute_catalogue' => $catalogue];
    }
}
