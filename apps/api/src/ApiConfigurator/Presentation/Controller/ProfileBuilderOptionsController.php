<?php

declare(strict_types=1);

namespace App\ApiConfigurator\Presentation\Controller;

use App\Catalog\Contracts\Service\AttributeCatalogReader;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * APIC-P4-04 — backing data for the API-profile builder (`api-profile-page`).
 *
 * `GET /api/api_profiles/builder_options` returns the tenant's selectable
 * attribute pool (code, label, type, group) for the profile's
 * `includedAttributes` multiselect, read through the Catalog
 * {@see AttributeCatalogReader} contract (ADR-0022 keeps cross-BC access to
 * Contracts only). The ObjectType multiselect is fed by the existing typed
 * Catalog resource `/api/object_types`; profile save (with the multiselect
 * arrays) reuses the existing ApiProfile CRUD — so this endpoint only supplies
 * the attribute side the builder can't trivially derive.
 */
final class ProfileBuilderOptionsController
{
    public function __construct(
        private readonly AttributeCatalogReader $attributes,
        private readonly TenantContext $tenantContext,
    ) {
    }

    // Path uses the `/api/profiles/` namespace (ProfileTestController), NOT the
    // API-Platform-owned `/api/api_profiles/` — `/api/api_profiles/builder_options`
    // would be shadowed by the generated `/api/api_profiles/{id}` item route.
    #[Route(
        '/api/profiles/builder_options',
        name: 'pim_api_profiles_builder_options',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'api_profile', action: 'read', anyOf: [
        'api_profile.read',
        'settings.integrations.manage',
    ])]
    public function __invoke(): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new LogicException('ProfileBuilderOptionsController requires an active tenant.');
        }

        $attributes = [];
        foreach ($this->attributes->findAllByTenant($tenant->getId()) as $attribute) {
            $attributes[] = [
                'code' => $attribute->code,
                'label' => $attribute->label,
                'type' => $attribute->type,
                'localizable' => $attribute->isLocalizable,
                'groupCode' => $attribute->groupCode,
                'groupLabel' => $attribute->groupLabel,
            ];
        }

        return new JsonResponse(['attributes' => $attributes]);
    }
}
