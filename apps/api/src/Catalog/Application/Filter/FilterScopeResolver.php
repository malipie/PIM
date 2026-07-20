<?php

declare(strict_types=1);

namespace App\Catalog\Application\Filter;

use App\Channel\Contracts\ScopeEnumeratorInterface;
use App\Shared\Application\TenantContext;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * #2673 — validates and resolves the `scope: {channel?, locale?}` root key
 * of a filter DSL document into a {@see FilterScopeContext}.
 *
 * The channel arrives as a CODE (UI dropdowns speak codes) and is mapped to
 * the `object_values.channel_id` UUID via the cross-BC scope enumerator
 * port; the locale must be one of the tenant's active short codes.
 *
 * v1 deliberately resolves scoped→global only — the per-tenant locale
 * FALLBACK CHAIN (pl→en→…) used by the read-path overlay is NOT applied
 * here; a condition sees the value for the exact (channel, locale) pair or
 * the global slot, nothing in between.
 */
final class FilterScopeResolver
{
    public function __construct(
        private readonly ScopeEnumeratorInterface $scopes,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @throws BadRequestHttpException on malformed scope, unknown channel
     *                                 code or inactive locale
     */
    public function resolve(mixed $scope): ?FilterScopeContext
    {
        if (null === $scope || [] === $scope) {
            return null;
        }
        if (!\is_array($scope)) {
            throw new BadRequestHttpException('FilterDsl `scope` must be an object {channel?, locale?}.');
        }

        $channelCode = $scope['channel'] ?? null;
        $locale = $scope['locale'] ?? null;
        if (null !== $channelCode && (!\is_string($channelCode) || '' === trim($channelCode))) {
            throw new BadRequestHttpException('FilterDsl `scope.channel` must be a non-empty channel code.');
        }
        if (null !== $locale && (!\is_string($locale) || '' === trim($locale))) {
            throw new BadRequestHttpException('FilterDsl `scope.locale` must be a non-empty locale code.');
        }
        if (null === $channelCode && null === $locale) {
            return null;
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('FilterDsl `scope` requires a tenant context.');
        }

        $channelId = null;
        if (null !== $channelCode) {
            $idsByCode = $this->scopes->channelIdsByCode($tenant);
            $channelId = $idsByCode[$channelCode] ?? null;
            if (null === $channelId) {
                throw new BadRequestHttpException(\sprintf(
                    'Unknown channel code "%s" in filter scope. Available: %s.',
                    $channelCode,
                    [] === $idsByCode ? '(none)' : implode(', ', array_keys($idsByCode)),
                ));
            }
        }

        if (null !== $locale) {
            $active = $this->scopes->localeShortCodes($tenant);
            if (!\in_array($locale, $active, true)) {
                throw new BadRequestHttpException(\sprintf(
                    'Locale "%s" is not active for this tenant. Active locales: %s.',
                    $locale,
                    [] === $active ? '(none)' : implode(', ', $active),
                ));
            }
        }

        return new FilterScopeContext(
            channelId: $channelId,
            channelCode: $channelCode,
            locale: $locale,
        );
    }
}
