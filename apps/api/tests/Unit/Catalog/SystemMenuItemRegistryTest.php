<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Catalog\Domain\SystemMenuItemRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * VIEW-08 (#427) — registry of in-code system menu items.
 */
final class SystemMenuItemRegistryTest extends TestCase
{
    #[Test]
    public function registryHasSevenSystemItems(): void
    {
        // dashboard, catalogs_pdf, multimedia, workflow, integrations,
        // settings, modeling. Po konsolidacji „Publikacje" + „Integracje"
        // (PR follow-up po #472) — top-level „Integracje" pełni rolę huba
        // dla Imports MVP + sub-tab API Configurator.
        self::assertCount(7, SystemMenuItemRegistry::items());
    }

    #[Test]
    public function integrationsRoutesToTheIntegrationsHub(): void
    {
        $integrations = SystemMenuItemRegistry::get('integrations');
        self::assertNotNull($integrations);
        self::assertSame('/integrations', $integrations['route']);
        self::assertSame('Plug2', $integrations['icon']);
        self::assertFalse($integrations['comingSoon']);
        self::assertFalse($integrations['protected']);
    }

    #[Test]
    public function publicationsKeyIsRetiredAfterConsolidation(): void
    {
        self::assertFalse(SystemMenuItemRegistry::exists('publications'));
        self::assertNotContains('publications', SystemMenuItemRegistry::defaultOrder());
    }

    #[Test]
    public function settingsAndModelingAreProtected(): void
    {
        self::assertTrue(SystemMenuItemRegistry::isProtected('settings'));
        self::assertTrue(SystemMenuItemRegistry::isProtected('modeling'));
        self::assertFalse(SystemMenuItemRegistry::isProtected('dashboard'));
        self::assertFalse(SystemMenuItemRegistry::isProtected('multimedia'));
    }

    #[Test]
    public function workflowShipsAsARoutedEntry(): void
    {
        // WFL-P3-02 (#2424) — the review queue ships, so the workflow
        // entry stops being a comingSoon placeholder and routes to
        // /workflow (still visibility-gated on workflow.view).
        $workflow = SystemMenuItemRegistry::get('workflow');
        self::assertNotNull($workflow);
        self::assertFalse($workflow['comingSoon']);
        self::assertSame('/workflow', $workflow['route']);
    }

    #[Test]
    public function existsRecognisesAllRegisteredKeys(): void
    {
        foreach (array_keys(SystemMenuItemRegistry::items()) as $key) {
            self::assertTrue(SystemMenuItemRegistry::exists($key), $key.' should exist');
        }
        self::assertFalse(SystemMenuItemRegistry::exists('not-a-key'));
    }

    #[Test]
    public function defaultOrderListsSystemItemsWithoutDashboardSlot(): void
    {
        $order = SystemMenuItemRegistry::defaultOrder();
        self::assertSame('dashboard', $order[0]);
        self::assertContains('settings', $order);
        self::assertContains('modeling', $order);
        // Services intentionally absent — operator adds it as a custom OT later.
        self::assertNotContains('services', $order);
    }
}
