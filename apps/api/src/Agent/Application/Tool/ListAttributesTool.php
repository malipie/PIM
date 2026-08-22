<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Service\AttributeCatalogReader;

/**
 * #2946 — read tool: find attributes by NAME as well as by code.
 *
 * Without it the agent knew only `search_catalog`, which finds objects and
 * not attributes. Asked to "change gender to Dla dziewczyn" it had to answer
 * that it cannot list attributes and ask the operator to go read the code out
 * of the modeling screen — which is the operator doing the agent's homework.
 *
 * Returns option codes for select/multiselect attributes, because that is the
 * other half of the same problem: an operator says "Dla dziewczyn", a write
 * accepts `female`, and something has to translate between the two.
 *
 * Read-only and label-shaped: codes, labels, types and options — never
 * values, so nothing here can leak past a per-attribute grant.
 */
final readonly class ListAttributesTool implements AgentToolInterface
{
    private const int MAX_RESULTS = 50;

    /** Options are enumerated only for the types that have them. */
    private const array OPTION_TYPES = ['select', 'multiselect'];

    public function __construct(
        private AttributeCatalogReader $attributes,
    ) {
    }

    public function name(): string
    {
        return 'list_attributes';
    }

    public function description(): string
    {
        return 'List the tenant\'s attributes, searching by NAME (label, any locale) or by code. '
            .'Call it before any attribute edit when the user names an attribute in prose ("płeć", "kolor") instead of giving its code. '
            .'Returns code, label, type, and — for select/multiselect — the allowed option codes with their labels, '
            .'which is what a write expects when the user names an option in prose ("Dla dziewczyn").';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Case-insensitive fragment matched against the attribute code and its label in every locale. Omit to list all.',
                ],
                'with_options' => [
                    'type' => 'boolean',
                    'description' => 'Include option codes for select/multiselect attributes. Default true.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum attributes to return (max 50).',
                ],
            ],
            'required' => [],
        ];
    }

    public function requiredPermission(): string
    {
        return 'object.read';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $query = \is_string($arguments['query'] ?? null) ? trim($arguments['query']) : '';
        $withOptions = !isset($arguments['with_options']) || true === $arguments['with_options'];
        $limit = \is_int($arguments['limit'] ?? null)
            ? max(1, min($arguments['limit'], self::MAX_RESULTS))
            : self::MAX_RESULTS;

        $tenantId = $context->tenant->getId();
        $matches = [];
        foreach ($this->attributes->findAllByTenant($tenantId) as $summary) {
            if ('' !== $query && !self::matches($summary->code, $summary->label, $query)) {
                continue;
            }

            $entry = [
                'code' => $summary->code,
                'label' => $summary->label,
                'type' => $summary->type,
                'is_localizable' => $summary->isLocalizable,
                'is_required' => $summary->isRequired,
                'group_code' => $summary->groupCode,
            ];

            if ($withOptions && \in_array($summary->type, self::OPTION_TYPES, true)) {
                $entry['options'] = $this->attributes->optionsFor($summary->id, $tenantId);
            }

            $matches[] = $entry;
            if (\count($matches) >= $limit) {
                break;
            }
        }

        return [
            'attributes' => $matches,
            'total_returned' => \count($matches),
            'query' => '' === $query ? null : $query,
        ];
    }

    /**
     * @param array<string, string> $label
     */
    private static function matches(string $code, array $label, string $query): bool
    {
        $needle = mb_strtolower($query);
        if (str_contains(mb_strtolower($code), $needle)) {
            return true;
        }

        foreach ($label as $translation) {
            if (str_contains(mb_strtolower($translation), $needle)) {
                return true;
            }
        }

        return false;
    }
}
