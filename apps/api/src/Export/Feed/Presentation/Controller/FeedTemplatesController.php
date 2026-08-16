<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Template\FeedTemplate;
use App\Export\Feed\Domain\Template\FeedTemplateCatalog;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * XMLF-P5-02 — read-only catalog of feed templates for the wizard's step 1
 * (SelectableCard grid). Serves the same in-code catalog the create endpoint
 * seeds descriptors from (XMLF-P2-02), plus the default mappings the wizard
 * copies into its state on template choice. Auto-registered into OpenAPI
 * (ADR-0020).
 */
final class FeedTemplatesController
{
    public function __construct(
        private readonly FeedTemplateCatalog $catalog,
    ) {
    }

    #[Route(path: '/api/feeds/templates', name: 'pim_feeds_templates', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all', anyOf: [
        'exports.view_all',
        'settings.integrations.manage',
    ])]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'items' => array_map($this->serialize(...), $this->catalog->all()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FeedTemplate $template): array
    {
        $root = \is_array($template->descriptor['root'] ?? null) ? $template->descriptor['root'] : [];
        $namespaces = \is_array($root['namespaces'] ?? null) ? $root['namespaces'] : [];

        return [
            'kind' => $template->kind->value,
            'built_in' => FeedTemplateKind::Custom !== $template->kind,
            'root_element' => \is_string($root['element'] ?? null) ? $root['element'] : null,
            'namespaces' => array_keys($namespaces),
            'descriptor' => $template->descriptor,
            'default_mappings' => $template->defaultMappings,
        ];
    }
}
