<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Template;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Template\FeedTemplateCatalog;
use Symfony\Component\Uid\Uuid;

/**
 * Creates a FeedProfile from a built-in template (ADR-0023 §6.5, XMLF-P2-06):
 * clones the template's descriptor + default mappings into an editable profile.
 * Predefs become a starting point the user then maps/filters; custom starts
 * from the blank template and is structure-editable (XMLF-P2-07).
 */
final class FeedProfileFactory
{
    public function __construct(private readonly FeedTemplateCatalog $catalog)
    {
    }

    public function fromTemplate(
        FeedTemplateKind $kind,
        string $code,
        string $name,
        Uuid $objectTypeId,
        ?string $locale = null,
        ?string $currency = null,
    ): FeedProfile {
        $template = $this->catalog->get($kind);

        return new FeedProfile(
            code: $code,
            name: $name,
            templateKind: $kind,
            objectTypeId: $objectTypeId,
            descriptor: $template->descriptor,
            fieldMappings: $template->defaultMappings,
            locale: $locale,
            currency: $currency,
        );
    }
}
