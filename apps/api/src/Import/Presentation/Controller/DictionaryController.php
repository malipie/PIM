<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Import\Application\Service\MappingDictionaryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only endpoint serving the rules-based dictionary so the wizard
 * can preview "did you mean" hints client-side without round-tripping
 * to {@see AutoMapController}.
 */
final class DictionaryController
{
    public function __construct(
        private readonly MappingDictionaryService $dictionary,
    ) {
    }

    #[Route(
        path: '/api/import-sessions/dictionary',
        name: 'imports_dictionary',
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'read', anyOf: [
        'import_session.read',
        'imports.view_own',
        'imports.view_all',
    ])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse(
            ['attributes' => $this->dictionary->load()],
            Response::HTTP_OK,
        );
        // Service-level cache is 5 min; mirror that on the HTTP layer
        // so browsers / proxies do not hammer the endpoint.
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
