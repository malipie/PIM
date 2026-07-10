<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Channel\Contracts\LocaleFallbackResolverInterface;
use App\Channel\Contracts\ScopeEnumeratorInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds and persists `CatalogObject.attributes_indexed` (JSONB cache)
 * + `CatalogObject.completeness` from the canonical `ObjectValue` rows.
 *
 * Called both:
 *   - synchronously from the Doctrine listener after a single-edit flush,
 *   - asynchronously from the bulk-rebuild Messenger handler.
 *
 * Indexing strategy (#1148): the cache holds only the GLOBAL reading
 * (locale=null, channel=null) — one entry per Attribute.code with its
 * JSONB `value` payload. Per-locale / per-channel `ObjectValue` rows are
 * skipped here and overlaid on the read path
 * ({@see \App\Catalog\Infrastructure\ApiPlatform\State\CatalogObjectLocaleOverlayProvider}).
 * Keeping the cache global keeps list views + Meilisearch deterministic.
 *
 * Completeness (#1152) reports, per scope, the share of
 * `ObjectType.completeness_rules.required` codes that resolve to a value:
 *   - `global`        — the global reading.
 *   - `per_locale[L]` — for each active non-primary locale L. A localizable
 *     code counts if it has a row in L (or anywhere on its fallback chain)
 *     or a global value; a non-localizable code counts if the global value
 *     exists.
 *   - `per_channel[C]`— for each tenant channel C against the effective
 *     required set `required ∪ required_per_channel[C]`. A scopable code
 *     counts if it has a row in C or a global value; non-scopable counts on
 *     the global value.
 * Granularity is flat (per_channel ignores locale, per_locale ignores
 * channel) per the jsonb-schemas contract; the channel×locale matrix is out
 * of scope.
 */
final readonly class AttributesIndexedRebuilder
{
    public function __construct(
        private EntityManagerInterface $em,
        private EffectiveAttributeGroupResolver $resolver,
        private ScopeEnumeratorInterface $scopes,
        private LocaleFallbackResolverInterface $fallback,
    ) {
    }

    /**
     * @param array<int, ObjectValue>|null $values Optional pre-loaded
     *                                             values for the object — saves one round-trip when the
     *                                             listener already has them in the unit of work. Pass null
     *                                             to make the rebuilder fetch fresh.
     */
    public function rebuild(CatalogObject $object, ?array $values = null): void
    {
        $values ??= $this->em->getRepository(ObjectValue::class)
            ->findBy(['object' => $object]);

        $indexed = [];
        foreach ($values as $value) {
            // #1148 — the denormalised cache holds ONLY the global reading
            // (locale=null, channel=null). Per-locale / per-channel rows are
            // surfaced on the read path by CatalogObjectLocaleOverlayProvider;
            // letting them into the cache makes lists + Meilisearch flicker
            // by last-written scope (non-deterministic).
            if (null !== $value->getLocale() || null !== $value->getChannelId()) {
                continue;
            }
            $indexed[$value->getAttribute()->getCode()] = $this->globalSlot($value);
        }

        $object->updateAttributeIndex($indexed);
        $object->recordCompleteness($this->computeCompleteness($object, $values, $indexed));
    }

    /**
     * The cache slot for one GLOBAL ObjectValue row — the value envelope
     * enriched with the provenance signal (AGENT-P6-05, #1978) so the
     * admin's badges answer "who set this?" without touching
     * object_values on read. The drift detector MUST derive its expected
     * reading through this same method: any second slot composer would
     * make every rebuilt object report a false mismatch.
     *
     * @return array<string, mixed>
     */
    public function globalSlot(ObjectValue $value): array
    {
        $slot = $value->getValue();
        $slot['provenance'] = $value->getProvenance()->value;
        $meta = $value->getProvenanceMeta();
        if (isset($meta['agent_run_id']) && \is_string($meta['agent_run_id'])) {
            // AICG-P0-02 (#2326) — content-generation audit fields ride along
            // when present; both optional, absent for pre-AICG rows. Malformed
            // shapes are dropped, never propagated (jsonb-schemas rule #1).
            $projected = ['agent_run_id' => $meta['agent_run_id']];
            if (isset($meta['recipe_id']) && \is_string($meta['recipe_id'])) {
                $projected['recipe_id'] = $meta['recipe_id'];
            }
            if (isset($meta['source_attributes']) && \is_array($meta['source_attributes'])) {
                $codes = array_values(array_filter($meta['source_attributes'], \is_string(...)));
                if ([] !== $codes) {
                    $projected['source_attributes'] = $codes;
                }
            }
            $slot['provenance_meta'] = $projected;
        }

        return $slot;
    }

    /**
     * @param array<int, ObjectValue> $values
     * @param array<string, mixed>    $indexed global reading, code => value
     *
     * @return array{global: int, per_locale?: array<string, int>, per_channel?: array<string, int>}
     */
    private function computeCompleteness(CatalogObject $object, array $values, array $indexed): array
    {
        $rules = $object->getObjectType()->getCompletenessRules();

        // ADR-014 / MOD-09 (#901) — only `required` entries inside the
        // current effective model participate; orphaned codes do not
        // penalise. Empty effective model keeps the legacy contract.
        $effectiveCodes = $this->effectiveAttributeCodes($object);
        $required = $this->filterByEffective($this->codeList($rules['required'] ?? null), $effectiveCodes);

        $globalPresent = 0;
        foreach ($required as $code) {
            if (\array_key_exists($code, $indexed)) {
                ++$globalPresent;
            }
        }

        $result = ['global' => $this->pct($globalPresent, \count($required))];

        $tenant = $object->getTenant();
        if (!$tenant instanceof Tenant) {
            // Fresh aggregate built inside the same flush — no tenant scope
            // to enumerate yet; the global reading is enough.
            return $result;
        }

        [$attrByCode, $localeRows, $channelRows] = $this->indexRows($values);

        $perLocale = $this->perLocale($required, $tenant, $attrByCode, $localeRows, $indexed);
        if ([] !== $perLocale) {
            $result['per_locale'] = $perLocale;
        }

        $perChannel = $this->perChannel($required, $rules, $effectiveCodes, $tenant, $attrByCode, $channelRows, $indexed);
        if ([] !== $perChannel) {
            $result['per_channel'] = $perChannel;
        }

        return $result;
    }

    /**
     * Single pass over the rows: attribute meta per code + the set of
     * locales / channel-ids each code carries a row for.
     *
     * @param array<int, ObjectValue> $values
     *
     * @return array{0: array<string, Attribute>, 1: array<string, array<string, true>>, 2: array<string, array<string, true>>}
     */
    private function indexRows(array $values): array
    {
        $attrByCode = [];
        $localeRows = [];
        $channelRows = [];
        foreach ($values as $value) {
            $code = $value->getAttribute()->getCode();
            $attrByCode[$code] ??= $value->getAttribute();

            $locale = $value->getLocale();
            if (null !== $locale) {
                $localeRows[$code][$locale] = true;
            }
            $channelId = $value->getChannelId();
            if (null !== $channelId) {
                $channelRows[$code][$channelId->toRfc4122()] = true;
            }
        }

        return [$attrByCode, $localeRows, $channelRows];
    }

    /**
     * @param list<string>                       $required
     * @param array<string, Attribute>           $attrByCode
     * @param array<string, array<string, true>> $localeRows
     * @param array<string, mixed>               $indexed
     *
     * @return array<string, int>
     */
    private function perLocale(array $required, Tenant $tenant, array $attrByCode, array $localeRows, array $indexed): array
    {
        $primary = $tenant->getPrimaryLocale();
        $perLocale = [];
        foreach ($this->scopes->localeShortCodes($tenant) as $locale) {
            if ($locale === $primary) {
                // The primary locale is stored as the global reading.
                continue;
            }
            $chain = $this->fallback->resolve($locale, $tenant);
            $present = 0;
            foreach ($required as $code) {
                if ($this->presentInLocale($code, $chain, $attrByCode, $localeRows, $indexed)) {
                    ++$present;
                }
            }
            $perLocale[$locale] = $this->pct($present, \count($required));
        }

        return $perLocale;
    }

    /**
     * @param list<string>                       $chain      fallback chain, most specific first
     * @param array<string, Attribute>           $attrByCode
     * @param array<string, array<string, true>> $localeRows
     * @param array<string, mixed>               $indexed
     */
    private function presentInLocale(string $code, array $chain, array $attrByCode, array $localeRows, array $indexed): bool
    {
        $attribute = $attrByCode[$code] ?? null;
        if (!$attribute instanceof Attribute) {
            return false;
        }
        if ($attribute->isLocalizable()) {
            foreach ($chain as $chainCode) {
                if (isset($localeRows[$code][$chainCode])) {
                    return true;
                }
            }

            return \array_key_exists($code, $indexed);
        }

        return \array_key_exists($code, $indexed);
    }

    /**
     * @param list<string>                       $required
     * @param array<string, mixed>               $rules
     * @param list<string>                       $effectiveCodes
     * @param array<string, Attribute>           $attrByCode
     * @param array<string, array<string, true>> $channelRows
     * @param array<string, mixed>               $indexed
     *
     * @return array<string, int>
     */
    private function perChannel(array $required, array $rules, array $effectiveCodes, Tenant $tenant, array $attrByCode, array $channelRows, array $indexed): array
    {
        $perChannelRules = \is_array($rules['required_per_channel'] ?? null) ? $rules['required_per_channel'] : [];

        $perChannel = [];
        foreach ($this->scopes->channelIdsByCode($tenant) as $channelCode => $channelId) {
            $extra = $this->filterByEffective($this->codeList($perChannelRules[$channelCode] ?? null), $effectiveCodes);
            $effective = array_values(array_unique([...$required, ...$extra]));

            $present = 0;
            foreach ($effective as $code) {
                if ($this->presentInChannel($code, $channelId, $attrByCode, $channelRows, $indexed)) {
                    ++$present;
                }
            }
            $perChannel[$channelCode] = $this->pct($present, \count($effective));
        }

        return $perChannel;
    }

    /**
     * @param array<string, Attribute>           $attrByCode
     * @param array<string, array<string, true>> $channelRows
     * @param array<string, mixed>               $indexed
     */
    private function presentInChannel(string $code, string $channelId, array $attrByCode, array $channelRows, array $indexed): bool
    {
        $attribute = $attrByCode[$code] ?? null;
        if (!$attribute instanceof Attribute) {
            return false;
        }
        if ($attribute->isScopable()) {
            return isset($channelRows[$code][$channelId]) || \array_key_exists($code, $indexed);
        }

        return \array_key_exists($code, $indexed);
    }

    private function pct(int $present, int $total): int
    {
        return 0 === $total ? 100 : (int) round(100 * $present / $total);
    }

    /**
     * @return list<string>
     */
    private function codeList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn (mixed $code): bool => \is_string($code)));
    }

    /**
     * @param list<string> $codes
     * @param list<string> $effectiveCodes
     *
     * @return list<string>
     */
    private function filterByEffective(array $codes, array $effectiveCodes): array
    {
        if ([] === $effectiveCodes) {
            return $codes;
        }

        return array_values(array_filter(
            $codes,
            static fn (string $code): bool => \in_array($code, $effectiveCodes, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function effectiveAttributeCodes(CatalogObject $object): array
    {
        $groups = $this->resolver->resolve($object);
        if ([] === $groups) {
            return [];
        }
        $byGroup = $this->resolver->loadGroupAttributes($groups);

        $codes = [];
        foreach ($byGroup as $junctions) {
            foreach ($junctions as $junction) {
                $codes[$junction->getAttribute()->getCode()] = true;
            }
        }

        return array_keys($codes);
    }
}
