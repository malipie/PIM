<?php

declare(strict_types=1);

namespace App\ApiConfigurator\Infrastructure\ApiPlatform;

use App\ApiConfigurator\Application\ApiProfileResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * #2527 — applies the resolved {@see \App\ApiConfigurator\Domain\Entity\ApiProfile}
 * as a row-selection scope on the LIVE API.
 *
 * The profile stores `filters` (a canonical query-parameter dict, e.g.
 * `{"status":"published","completeness":{"gte":80}}`) and `objectTypeIds`.
 * Historically these were persisted but never applied — only the serializer
 * pruned FIELDS (ADR-0022, #94), so an integrator scoped to `status=published`
 * still received drafts. This listener injects them into the request query
 * BEFORE API Platform builds `$context['filters']`, so the existing Catalog
 * filter chain (StatusFilter, CompletenessFilter, AttributeFilter, …) and
 * {@see \App\Catalog\Infrastructure\ApiPlatform\Filter\ObjectTypeIdsFilter}
 * narrow the collection with zero re-implementation.
 *
 * The profile is a HARD scope: its values overwrite any integrator-supplied
 * query param of the same name (an integrator cannot widen the scope by
 * passing `?status=draft`). Communication with Catalog is via query params
 * only — no cross-BC dependency (ADR-0031).
 *
 * Runs after the security firewall (priority 8) so the API key is
 * authenticated and the profile resolvable; before API Platform reads the
 * query into the filter context.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final readonly class ApiProfileFilterRequestListener
{
    public function __construct(
        private ApiProfileResolver $resolver,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            $profile = $this->resolver->resolveFromRequest($request);
        } catch (AccessDeniedHttpException) {
            // A key not scoped to the requested profile is rejected downstream
            // (serializer context builder). Do not scope here.
            return;
        }

        if (null === $profile) {
            return;
        }

        // Profile filters win over integrator-supplied params (hard scope).
        foreach ($profile->getFilters() as $key => $value) {
            if ('' !== $key && (\is_scalar($value) || \is_array($value) || null === $value)) {
                $request->query->set($key, $value);
            }
        }

        $objectTypeIds = $profile->getObjectTypeIds();
        if ([] !== $objectTypeIds) {
            $request->query->set('objectTypeIds', $objectTypeIds);
        }
    }
}
