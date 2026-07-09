<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application;

use App\Export\Catalog\Domain\Template\CatalogTemplate;

/**
 * Validates a catalog profile's branding and field mappings before they are
 * persisted or used to render (ADR-0027, CPDF-P2-01). Brand colours are
 * injected verbatim into the Twig `<style>` block, so a malformed colour must
 * never reach the template; likewise mappings may only bind template slots.
 */
final class CatalogBrandingGuard
{
    private const string COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * @param array<string, mixed> $branding
     *
     * @throws InvalidBrandingException
     */
    public function assertValidBranding(array $branding): void
    {
        if (array_key_exists('color', $branding)) {
            $color = $branding['color'];
            if (!\is_string($color) || 1 !== preg_match(self::COLOR_PATTERN, $color)) {
                throw new InvalidBrandingException('Branding "color" must be a #RRGGBB hex string.');
            }
        }

        if (array_key_exists('logo', $branding)) {
            $logo = $branding['logo'];
            if (!\is_string($logo) || '' === $logo) {
                throw new InvalidBrandingException('Branding "logo" must be a non-empty string.');
            }
        }

        if (array_key_exists('company_name', $branding) && !\is_string($branding['company_name'])) {
            throw new InvalidBrandingException('Branding "company_name" must be a string.');
        }
    }

    /**
     * @param list<array{slot: string, source: string}> $fieldMappings
     *
     * @throws InvalidBrandingException
     */
    public function assertMappingsMatchTemplate(array $fieldMappings, CatalogTemplate $template): void
    {
        $slotNames = $template->slotNames();

        foreach ($fieldMappings as $mapping) {
            if (!\in_array($mapping['slot'], $slotNames, true)) {
                throw new InvalidBrandingException(sprintf(
                    'Mapping slot "%s" is not exposed by template "%s".',
                    $mapping['slot'],
                    $template->kind->value,
                ));
            }
        }
    }
}
