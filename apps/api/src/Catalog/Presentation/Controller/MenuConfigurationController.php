<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\MenuConfigurationService;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\SystemMenuItemRegistry;
use App\Catalog\Domain\Value\MenuItemRecord;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

use const DATE_ATOM;

/**
 * VIEW-08 (#427) — singleton-per-tenant configuration of the main sidebar.
 *
 * Three routes, all registered with priority 200 so they sit alongside the
 * read-only API Platform resources without collision:
 *
 *   GET  /api/menu_configuration             → raw items + tenant id
 *   PUT  /api/menu_configuration             → replace items atomically
 *   GET  /api/menu_configuration/effective   → resolved render-ready view
 *                                              (visible[] + available[])
 *
 * The `effective` endpoint is the one the sidebar + settings page actually
 * consume — it merges system items (from SystemMenuItemRegistry, code) and
 * object_type items (from ObjectType.exposeToMainMenu rows in DB) and
 * resolves labels per locale, icons, routes, comingSoon/protected flags.
 *
 * No CORS, no `Access-Control-Allow-Origin` — single-origin via Caddy
 * (CLAUDE.md sekcja 3.10a). Tenant scoping comes from CurrentTenantProvider
 * → `TenantFilter`, no manual tenant_id in queries.
 */
final class MenuConfigurationController
{
    public function __construct(
        private readonly MenuConfigurationService $service,
        private readonly TenantContext $tenantContext,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
    ) {
    }

    #[Route(
        '/api/menu_configuration',
        name: 'pim_menu_configuration_get',
        methods: ['GET'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings.users', action: 'manage')]
    public function get(): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'No tenant in current security context.');
        }

        $config = $this->service->getOrCreate($tenant);

        return new JsonResponse([
            'id' => $config->getId()->toRfc4122(),
            'items' => array_map(
                static fn (MenuItemRecord $r): array => $r->toArray(),
                $config->getItems(),
            ),
            'updatedAt' => $config->getUpdatedAt()->format(DATE_ATOM),
        ]);
    }

    #[Route(
        '/api/menu_configuration',
        name: 'pim_menu_configuration_replace',
        methods: ['PUT'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings.users', action: 'manage')]
    public function replace(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'No tenant in current security context.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        if (!\is_array($body['items'] ?? null)) {
            throw new BadRequestHttpException('Body must contain an "items" array.');
        }

        $items = [];
        foreach ($body['items'] as $rawItem) {
            if (!\is_array($rawItem)) {
                throw new BadRequestHttpException('Each item must be an object.');
            }
            $normalized = [];
            foreach ($rawItem as $key => $value) {
                if (!\is_string($key)) {
                    throw new BadRequestHttpException('Each item key must be a string.');
                }
                $normalized[$key] = $value;
            }
            try {
                $items[] = MenuItemRecord::fromArray($normalized);
            } catch (Throwable $e) {
                throw new BadRequestHttpException($e->getMessage(), $e);
            }
        }

        try {
            $config = $this->service->replace($tenant, $items);
        } catch (Throwable $e) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage(), $e);
        }

        return new JsonResponse([
            'id' => $config->getId()->toRfc4122(),
            'items' => array_map(
                static fn (MenuItemRecord $r): array => $r->toArray(),
                $config->getItems(),
            ),
            'updatedAt' => $config->getUpdatedAt()->format(DATE_ATOM),
        ]);
    }

    #[Route(
        '/api/menu_configuration/effective',
        name: 'pim_menu_configuration_effective',
        methods: ['GET'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // #2874 — the caller's OWN effective menu, the same shape as /api/me:
    // the authenticated user is the implicit subject, so there is nothing
    // to authorise beyond being logged in. It used to ask for the legacy
    // `user.read`, which no PRD role holds — so every member of a tenant
    // created through the admin UI got a 403 here and the sidebar silently
    // fell back to the default menu.
    #[NoPermissionRequired(reason: 'Returns the caller\'s own effective menu; authentication is the whole gate.')]
    public function effective(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'No tenant in current security context.');
        }

        $locale = $this->resolveLocale($request);

        $config = $this->service->getOrCreate($tenant);

        // Index ObjectTypes by id for O(1) lookups while resolving the
        // configured items + diffing the available set.
        $exposed = array_filter(
            $this->objectTypes->findAllByTenant($tenant),
            static fn (ObjectType $ot): bool => $ot->isExposedToMainMenu()
                && ObjectKind::Asset !== $ot->getKind(),
        );
        /** @var array<string, ObjectType> $byId */
        $byId = [];
        foreach ($exposed as $ot) {
            $byId[$ot->getId()->toRfc4122()] = $ot;
        }

        $visible = [];
        $configuredObjectTypeIds = [];
        $items = $config->getItems();
        // Sort the items list by position to guarantee stable order
        // regardless of how the FE submitted it.
        usort(
            $items,
            static fn (MenuItemRecord $a, MenuItemRecord $b): int => $a->position <=> $b->position,
        );

        foreach ($items as $item) {
            if (MenuItemRecord::KIND_OBJECT_TYPE === $item->kind) {
                $configuredObjectTypeIds[] = $item->ref;
            }
            if (!$item->visible) {
                continue;
            }
            $resolved = $this->resolveItem($item, $byId, $locale);
            if (null === $resolved) {
                continue; // dangling ref — skip silently, log via APM in follow-up
            }
            $visible[] = $resolved;
        }

        // `available` = exposed object_types not present in items at all,
        // plus exposed object_types whose items entry is visible=false.
        $available = [];
        $hiddenIds = [];
        foreach ($items as $item) {
            if (MenuItemRecord::KIND_OBJECT_TYPE === $item->kind && !$item->visible) {
                $hiddenIds[] = $item->ref;
            }
        }
        foreach ($byId as $id => $ot) {
            if (\in_array($id, $configuredObjectTypeIds, true) && !\in_array($id, $hiddenIds, true)) {
                continue;
            }
            $builtInLabelKey = $ot->isBuiltIn() ? $this->builtInLabelKey($ot->getKind()) : null;
            $available[] = [
                'id' => 'object_type:'.$id,
                'kind' => MenuItemRecord::KIND_OBJECT_TYPE,
                'ref' => $id,
                'label' => null !== $builtInLabelKey ? null : $this->resolveObjectTypeLabel($ot, $locale),
                'labelKey' => $builtInLabelKey,
                'icon' => $ot->getIcon() ?? 'Boxes',
                'route' => $this->routeForObjectType($ot),
                'comingSoon' => false,
                'protected' => false,
                'objectTypeKind' => $ot->getKind()->value,
                'objectTypeCode' => $ot->getCode(),
            ];
        }

        return new JsonResponse([
            'visible' => $visible,
            'available' => $available,
        ]);
    }

    /**
     * @param array<string, ObjectType> $byId
     *
     * @return array<string, mixed>|null
     */
    private function resolveItem(MenuItemRecord $item, array $byId, string $locale): ?array
    {
        if (MenuItemRecord::KIND_SYSTEM === $item->kind) {
            $row = SystemMenuItemRegistry::get($item->ref);
            if (null === $row) {
                return null;
            }

            return [
                'id' => 'system:'.$item->ref,
                'kind' => MenuItemRecord::KIND_SYSTEM,
                'ref' => $item->ref,
                'label' => null, // FE uses i18n key — see labelKey
                'labelKey' => $row['labelKey'],
                'icon' => $row['icon'],
                'route' => $row['route'],
                'comingSoon' => $row['comingSoon'],
                'protected' => $row['protected'],
                'position' => $item->position,
            ];
        }

        $ot = $byId[$item->ref] ?? null;
        if (null === $ot) {
            return null;
        }

        // Built-in ObjectTypes carry singular labels in the DB ("Produkt",
        // "Marka") because the modeling UI titles them as a kind. The sidebar
        // wants the plural ("Produkty", "Marki") which already exists as the
        // legacy `nav.*` i18n keys. Surface those as `labelKey` so the FE
        // i18n resolves the right form. Custom types use the DB label as-is.
        $builtInLabelKey = $ot->isBuiltIn()
            ? $this->builtInLabelKey($ot->getKind())
            : null;

        return [
            'id' => 'object_type:'.$item->ref,
            'kind' => MenuItemRecord::KIND_OBJECT_TYPE,
            'ref' => $item->ref,
            'label' => null !== $builtInLabelKey ? null : $this->resolveObjectTypeLabel($ot, $locale),
            'labelKey' => $builtInLabelKey,
            'icon' => $ot->getIcon() ?? 'Boxes',
            'route' => $this->routeForObjectType($ot),
            'comingSoon' => false,
            'protected' => false,
            'position' => $item->position,
            'objectTypeKind' => $ot->getKind()->value,
            'objectTypeCode' => $ot->getCode(),
        ];
    }

    private function builtInLabelKey(ObjectKind $kind): ?string
    {
        return match ($kind) {
            ObjectKind::Product => 'nav.products',
            ObjectKind::Category => 'nav.categories',
            ObjectKind::Asset => 'nav.multimedia',
            default => null,
        };
    }

    private function resolveObjectTypeLabel(ObjectType $ot, string $locale): string
    {
        $label = $ot->getLabel();
        if (isset($label[$locale]) && '' !== $label[$locale]) {
            return $label[$locale];
        }
        // Fallback to first available locale, then code.
        foreach ($label as $value) {
            if ('' !== $value) {
                return $value;
            }
        }

        return $ot->getCode();
    }

    private function routeForObjectType(ObjectType $ot): string
    {
        // Built-in Product gets the legacy /products sugar path. Other
        // exposed ObjectTypes route to /objects/{code} which will be
        // implemented as a generic listing page in B-2 (out of scope).
        if (ObjectKind::Product === $ot->getKind() && $ot->isBuiltIn()) {
            return '/products';
        }

        return '/objects/'.$ot->getCode();
    }

    private function resolveLocale(Request $request): string
    {
        $accept = $request->headers->get('Accept-Language');
        if (null === $accept || '' === $accept) {
            return 'pl';
        }

        // FE sends e.g. "pl-PL,pl;q=0.9,en-US;q=0.8" — take the first
        // segment and strip the region tag for our 2-letter label keys.
        $primary = preg_split('/[,;]/', $accept, 2)[0] ?? 'pl';
        $segment = preg_split('/-/', $primary, 2)[0] ?? 'pl';

        return strtolower($segment);
    }
}
