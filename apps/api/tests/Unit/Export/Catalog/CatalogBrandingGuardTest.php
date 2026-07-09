<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Application\CatalogBrandingGuard;
use App\Export\Catalog\Application\InvalidBrandingException;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CPDF-P2-01 — the branding guard accepts well-formed branding and mappings and
 * rejects a malformed brand colour or a mapping that targets an unknown slot.
 */
final class CatalogBrandingGuardTest extends TestCase
{
    #[Test]
    public function acceptsValidBranding(): void
    {
        $guard = new CatalogBrandingGuard();

        $guard->assertValidBranding([
            'color' => '#0ea5e9',
            'company_name' => 'ACME',
            'logo' => 'https://cdn.example.test/logo.png',
            'unknown_key' => 'ignored',
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function rejectsNonHexColour(): void
    {
        $this->expectException(InvalidBrandingException::class);
        new CatalogBrandingGuard()->assertValidBranding(['color' => 'notahex']);
    }

    #[Test]
    public function rejectsEmptyLogo(): void
    {
        $this->expectException(InvalidBrandingException::class);
        new CatalogBrandingGuard()->assertValidBranding(['logo' => '']);
    }

    #[Test]
    public function rejectsMappingToUnknownSlot(): void
    {
        $template = new CatalogTemplateCatalog()->get(CatalogTemplateKind::Sheet);

        $this->expectException(InvalidBrandingException::class);
        new CatalogBrandingGuard()->assertMappingsMatchTemplate(
            [['slot' => 'nope', 'source' => 'whatever']],
            $template,
        );
    }

    #[Test]
    public function acceptsMappingToRealSlot(): void
    {
        $template = new CatalogTemplateCatalog()->get(CatalogTemplateKind::Sheet);

        new CatalogBrandingGuard()->assertMappingsMatchTemplate(
            [
                ['slot' => 'title', 'source' => 'name'],
                ['slot' => 'specs', 'source' => 'attributes'],
            ],
            $template,
        );

        $this->expectNotToPerformAssertions();
    }
}
