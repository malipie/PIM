<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Entity\MenuConfiguration;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\MenuConfigurationRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\SystemMenuItemRegistry;
use App\Catalog\Domain\Value\MenuItemRecord;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * VIEW-08 (#427) — seed the default menu layout for a tenant.
 *
 * Reproduces the legacy hard-coded sidebar order from
 * `apps/admin/src/layout/sidebar-nav.tsx:39-63` minus `services` (operator
 * adds Services as a custom ObjectType later — VIEW-08 ticket body).
 *
 * Idempotent: re-runs are no-ops once a row exists for the tenant.
 *
 * Order:
 *   1. Pulpit (system:dashboard)
 *   2. Produkty (object_type:built-in product)
 *   3. Katalogi PDF (system:catalogs_pdf)
 *   4. Multimedia (system:multimedia)
 *   5. Workflow (system:workflow, comingSoon=true visible)
 *   6. Integracje (system:integrations)
 *   7. Ustawienia (system:settings, protected)
 *   8. Modelowanie (system:modeling, protected)
 *
 * If the built-in Product is missing (early tenant, fixtures order issue),
 * we skip it gracefully — `MenuConfigurationService` will pick up the
 * Product entry once it appears (next request fetches `effective` and
 * sees Product as `available`, operator can promote it).
 */
final readonly class DefaultMenuSeeder
{
    public function __construct(
        private MenuConfigurationRepositoryInterface $repository,
        private ObjectTypeRepositoryInterface $objectTypes,
        private TenantContext $tenantContext,
        private EntityManagerInterface $em,
    ) {
    }

    public function seed(Tenant $tenant): MenuConfiguration
    {
        $existing = $this->repository->findByTenant($tenant);
        if (null !== $existing) {
            return $existing;
        }

        $previous = $this->tenantContext->get();
        $this->tenantContext->set($tenant);

        try {
            $config = new MenuConfiguration();

            $items = [];
            $position = 0;

            // 1. Pulpit
            $items[] = new MenuItemRecord(
                MenuItemRecord::KIND_SYSTEM,
                'dashboard',
                $position++,
                true,
            );

            // 2. Produkty (interleaved from object_type) — only if seeded.
            $product = $this->objectTypes->findBuiltInByKind(ObjectKind::Product, $tenant);
            if (null !== $product) {
                $items[] = new MenuItemRecord(
                    MenuItemRecord::KIND_OBJECT_TYPE,
                    $product->getId()->toRfc4122(),
                    $position++,
                    true,
                );
            }

            // 3-8. The remaining system items (minus `dashboard`, already at position 0).
            foreach (SystemMenuItemRegistry::defaultOrder() as $systemKey) {
                if ('dashboard' === $systemKey) {
                    continue;
                }
                $items[] = new MenuItemRecord(
                    MenuItemRecord::KIND_SYSTEM,
                    $systemKey,
                    $position++,
                    true,
                );
            }

            $config->replaceItems($items);
            $this->em->persist($config);
            $this->em->flush();

            return $config;
        } finally {
            if (null === $previous) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->set($previous);
            }
        }
    }
}
