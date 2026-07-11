<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/**
 * VIEW-08 (#427) — registry of built-in system menu items.
 *
 * System items are the parts of the admin sidebar that are not domain
 * objects (Pulpit, Multimedia, Workflow, Integracje, Ustawienia,
 * Modelowanie). They live in code (not in the database) because they
 * are wired to specific FE routes and components — they exist iff the
 * admin is built with the matching pages. `MenuConfiguration.items`
 * stores only the *which-where* (position + visible) keyed by these
 * registry keys.
 *
 * `protected: true` items cannot be hidden through the UI. The two
 * obvious candidates are `settings` and `modeling` — without them the
 * operator cannot get back to this very page or open ObjectType detail,
 * so we lock them visible by service-level invariant rather than relying
 * on the FE to enforce.
 *
 * `comingSoon: true` items render as disabled `<span>` in the sidebar
 * with a "Wkrótce" badge — the route is null so a NavLink would 404.
 *
 * `route: null` is reserved for coming-soon entries; everything else
 * routes to a real admin page that exists today.
 */
final class SystemMenuItemRegistry
{
    public const string KIND = 'system';

    /**
     * @return array<string, array{
     *     route: ?string,
     *     icon: string,
     *     labelKey: string,
     *     comingSoon: bool,
     *     protected: bool
     * }>
     */
    public static function items(): array
    {
        return [
            'dashboard' => [
                'route' => '/dashboard',
                'icon' => 'LayoutDashboard',
                'labelKey' => 'nav.dashboard',
                'comingSoon' => false,
                'protected' => false,
            ],
            'catalogs_pdf' => [
                'route' => '/catalogs-pdf',
                'icon' => 'FileText',
                'labelKey' => 'nav.catalogsPdf',
                'comingSoon' => false,
                'protected' => false,
            ],
            'multimedia' => [
                'route' => '/assets',
                'icon' => 'Image',
                'labelKey' => 'nav.multimedia',
                'comingSoon' => false,
                'protected' => false,
            ],
            'workflow' => [
                // WFL-P3-02 (#2424) — the review queue ships; the entry
                // stops being a comingSoon placeholder. Menu visibility
                // stays gated on workflow.view (menu-permissions.ts).
                'route' => '/workflow',
                'icon' => 'Workflow',
                'labelKey' => 'nav.workflow',
                'comingSoon' => false,
                'protected' => false,
            ],
            // Top-level „Integracje" hub — Imports MVP (0.13/UI-09) + sub-tab
            // API Configurator (Profile API, VIEW-08) + placeholdery Exports /
            // Connectors. Kiedyś (krótkofalowo po IMP-09) `publications` i
            // `integrations` były osobnymi top-level itemami; konsolidacja
            // została zrobiona po smoke teście operatora który wskazał
            // duplikację (PR follow-up po #472).
            'integrations' => [
                'route' => '/integrations',
                'icon' => 'Plug2',
                'labelKey' => 'nav.integrations',
                'comingSoon' => false,
                'protected' => false,
            ],
            'settings' => [
                'route' => '/settings',
                'icon' => 'Cog',
                'labelKey' => 'nav.settings',
                'comingSoon' => false,
                'protected' => true,
            ],
            'modeling' => [
                'route' => '/modeling',
                'icon' => 'Settings2',
                'labelKey' => 'nav.modeling',
                'comingSoon' => false,
                'protected' => true,
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return \array_key_exists($key, self::items());
    }

    public static function isProtected(string $key): bool
    {
        return self::items()[$key]['protected'] ?? false;
    }

    /**
     * @return array{route: ?string, icon: string, labelKey: string, comingSoon: bool, protected: bool}|null
     */
    public static function get(string $key): ?array
    {
        return self::items()[$key] ?? null;
    }

    /**
     * Default seed order replicating the legacy hard-coded sidebar — minus
     * `services` (operator adds it manually as a custom ObjectType later
     * per VIEW-08 ticket body).
     *
     * Built-in `Product` is interleaved at position 1 by `DefaultMenuSeeder`
     * because it is an `object_type` ref, not a system one — this list only
     * yields the system ordering.
     *
     * @return list<string>
     */
    public static function defaultOrder(): array
    {
        return [
            'dashboard',
            // <- product object_type slot here (handled by DefaultMenuSeeder)
            'catalogs_pdf',
            'multimedia',
            'workflow',
            'integrations',
            'settings',
            'modeling',
        ];
    }
}
