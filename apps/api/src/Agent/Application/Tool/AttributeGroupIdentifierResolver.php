<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Service\AttributeGroupCatalogReader;
use Symfony\Component\Uid\Uuid;

/** Resolves an exact group code or an exact localized label to its canonical code. */
final readonly class AttributeGroupIdentifierResolver
{
    public function __construct(private AttributeGroupCatalogReader $groups)
    {
    }

    /**
     * @param list<string>                                            $identifiers
     * @param list<array{code: string, label: array<string, string>}> $additionalGroups groups proposed in the same schema batch
     *
     * @return array{codes: list<string>, unresolved: list<string>, ambiguous: array<string, list<string>>}
     */
    public function resolve(array $identifiers, Uuid $tenantId, array $additionalGroups = []): array
    {
        $summaries = array_map(
            static fn ($group): array => ['code' => $group->code, 'label' => $group->label],
            $this->groups->findAllByTenant($tenantId),
        );
        $summaries = [...$summaries, ...$additionalGroups];
        $codes = [];
        $unresolved = [];
        $ambiguous = [];

        foreach ($identifiers as $identifier) {
            $needle = self::normalize($identifier);
            $codeMatches = array_values(array_filter(
                $summaries,
                static fn (array $group): bool => self::normalize($group['code']) === $needle,
            ));
            $matches = [] !== $codeMatches ? $codeMatches : array_values(array_filter(
                $summaries,
                static fn (array $group): bool => in_array($needle, array_map(self::normalize(...), $group['label']), true),
            ));

            $matchedCodes = array_values(array_unique(array_map(static fn (array $group): string => $group['code'], $matches)));
            if (1 === \count($matchedCodes)) {
                $codes[] = $matchedCodes[0];
            } elseif ([] === $matchedCodes) {
                $unresolved[] = $identifier;
            } else {
                $ambiguous[$identifier] = $matchedCodes;
            }
        }

        return [
            'codes' => array_values(array_unique($codes)),
            'unresolved' => $unresolved,
            'ambiguous' => $ambiguous,
        ];
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
