<?php

declare(strict_types=1);

namespace App\Agent\Presentation\Controller;

use App\Agent\Domain\Entity\ContentRecipe;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * AICG-P1-03 (#2329) — the only write path off built-in recipes:
 * POST /api/content-recipes/{id}/clone copies a recipe (built-in or
 * not) into an editable, non-built-in duplicate. Optional JSON body
 * {code?, name?}; defaults derive from the source ("<code>_copy").
 * Procedural operation → custom route per ADR-0020; folded into the
 * OpenAPI export by CustomRouteOpenApiFactory.
 */
final class ContentRecipeCloneController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/content-recipes/{id}/clone', name: 'pim_agent_content_recipe_clone', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings.ai_content', action: 'create')]
    public function clone(string $id, Request $request): JsonResponse
    {
        $source = $this->em->find(ContentRecipe::class, $id);
        if (!$source instanceof ContentRecipe) {
            throw new NotFoundHttpException('Content recipe not found.');
        }

        $raw = $request->getContent();
        try {
            /** @var array<string, mixed> $body */
            $body = '' === $raw ? [] : (array) json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('Request body must be valid JSON.');
        }
        $code = $body['code'] ?? $source->getCode().'_copy';
        $name = $body['name'] ?? $source->getName().' (kopia)';
        if (!\is_string($code) || '' === $code || 1 !== preg_match('/^[a-z0-9_]+$/', $code)) {
            throw new BadRequestHttpException('code may contain only lowercase letters, digits and underscores.');
        }
        if (!\is_string($name) || '' === $name) {
            throw new BadRequestHttpException('name must be a non-empty string.');
        }

        $clone = new ContentRecipe(
            code: $code,
            name: $name,
            targetAttribute: $source->getTargetAttribute(),
            sourceAttributes: $source->getSourceAttributes(),
            constraints: $source->getConstraints(),
            objectTypeId: $source->getObjectTypeId(),
        );
        $clone->updateAppliesTo($source->getAppliesTo());
        $clone->updateToneHint($source->getToneHint());
        $clone->attachBrandVoice($source->getBrandVoiceId());

        $this->em->persist($clone);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new ConflictHttpException(\sprintf('A content recipe with code "%s" already exists.', $code), $e);
        }

        return new JsonResponse(
            [
                'id' => $clone->getId()->toRfc4122(),
                'code' => $clone->getCode(),
                'name' => $clone->getName(),
                'is_built_in' => false,
                'cloned_from' => Uuid::fromString($id)->toRfc4122(),
            ],
            Response::HTTP_CREATED,
        );
    }
}
