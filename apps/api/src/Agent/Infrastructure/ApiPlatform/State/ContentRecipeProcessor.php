<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\ApiPlatform\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Agent\Infrastructure\ApiPlatform\Resource\ContentRecipeInput;
use App\Agent\Infrastructure\ApiPlatform\Resource\ContentRecipePatchInput;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P1-03 (#2329) — write path of /api/content-recipes. Thin
 * EntityManager bridge (the settings entities carry their invariants in
 * aggregate guards, so no CQRS command layer sits in between — unlike
 * the Catalog processors that reuse existing handlers):
 *
 *   - aggregate guard violations (constraints.format, source codes)
 *     surface as 422,
 *   - UNIQUE(tenant_id, code) as 409,
 *   - built-in recipes reject PATCH/DELETE with 409 pointing to the
 *     clone route — the seeded templates stay intact (ADR-0030).
 *
 * @implements ProcessorInterface<ContentRecipeInput|ContentRecipePatchInput|ContentRecipe, ContentRecipe|null>
 */
final readonly class ContentRecipeProcessor implements ProcessorInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ContentRecipe
    {
        if ($operation instanceof DeleteOperationInterface) {
            $recipe = $this->find($uriVariables);
            if ($recipe->isBuiltIn()) {
                throw new ConflictHttpException('Built-in recipes cannot be deleted — clone them and edit the copy.');
            }
            $this->em->remove($recipe);
            $this->em->flush();

            return null;
        }

        if ($operation instanceof Post) {
            return $this->create($data);
        }

        if ($operation instanceof Patch) {
            return $this->update($data, $uriVariables);
        }

        throw new LogicException(\sprintf('ContentRecipeProcessor cannot handle operation "%s".', $operation::class));
    }

    private function create(mixed $data): ContentRecipe
    {
        if (!$data instanceof ContentRecipeInput) {
            throw new LogicException('ContentRecipeProcessor expects ContentRecipeInput on Post.');
        }

        try {
            $recipe = new ContentRecipe(
                code: $data->code,
                name: $data->name,
                targetAttribute: $data->targetAttribute,
                sourceAttributes: $data->sourceAttributes,
                constraints: $data->constraints,
                objectTypeId: null !== $data->objectTypeId ? Uuid::fromString($data->objectTypeId) : null,
            );
            if ([] !== $data->appliesTo) {
                $recipe->updateAppliesTo($data->appliesTo);
            }
            $recipe->updateToneHint($data->toneHint);
            $recipe->attachBrandVoice(null !== $data->brandVoiceId ? Uuid::fromString($data->brandVoiceId) : null);
        } catch (InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        $this->em->persist($recipe);
        $this->flushMappingUniqueToConflict($data->code);

        return $recipe;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function update(mixed $data, array $uriVariables): ContentRecipe
    {
        if (!$data instanceof ContentRecipePatchInput) {
            throw new LogicException('ContentRecipeProcessor expects ContentRecipePatchInput on Patch.');
        }

        $recipe = $this->find($uriVariables);
        if ($recipe->isBuiltIn()) {
            throw new ConflictHttpException('Built-in recipes are read-only — clone them and edit the copy.');
        }

        try {
            if (null !== $data->name) {
                $recipe->rename($data->name);
            }
            if (null !== $data->targetAttribute) {
                $recipe->retarget($data->targetAttribute);
            }
            if (null !== $data->sourceAttributes) {
                $recipe->updateSourceAttributes($data->sourceAttributes);
            }
            if (null !== $data->constraints) {
                $recipe->updateConstraints($data->constraints);
            }
            if (null !== $data->appliesTo) {
                $recipe->updateAppliesTo($data->appliesTo);
            }
            if (null !== $data->toneHint) {
                $recipe->updateToneHint($data->toneHint);
            }
            if (null !== $data->brandVoiceId) {
                $recipe->attachBrandVoice(Uuid::fromString($data->brandVoiceId));
            }
        } catch (InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        $this->em->flush();

        return $recipe;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function find(array $uriVariables): ContentRecipe
    {
        $id = $uriVariables['id'] ?? null;
        $recipe = \is_string($id) || $id instanceof Uuid
            ? $this->em->find(ContentRecipe::class, $id)
            : null;
        if (!$recipe instanceof ContentRecipe) {
            throw new NotFoundHttpException('Content recipe not found.');
        }

        return $recipe;
    }

    private function flushMappingUniqueToConflict(string $code): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new ConflictHttpException(\sprintf('A content recipe with code "%s" already exists.', $code), $e);
        }
    }
}
