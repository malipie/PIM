<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\UserFilterFavorite;
use App\Catalog\Domain\Repository\UserFilterFavoriteRepositoryInterface;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Shared\Application\UserIdentityAware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-27 (#558) — per-user attribute favorites endpoints.
 *
 * `GET /api/users/me/filter-favorites` returns the operator's current
 * shortcut list, eager-joined with `Attribute` so the FE picker has
 * `{id, code, label}` without a second roundtrip.
 *
 * `PUT /api/users/me/filter-favorites` atomically replaces the entire
 * list. Body: `{ "attribute_ids": ["uuid", ...] }`. Max 10 entries
 * (400 otherwise) — the picker UX caps at 10 ulubione.
 *
 * Lives in Catalog (not Identity) because both `Attribute` and the
 * repository sit in Catalog; the user identity is obtained via the
 * shared `UserIdentityAware` contract so the controller never imports
 * `App\Identity\Domain\Entity\User`.
 */
final class UserFilterFavoritesController
{
    public const int MAX_FAVORITES = 10;

    public function __construct(
        private readonly Security $security,
        private readonly UserFilterFavoriteRepositoryInterface $favorites,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/users/me/filter-favorites', name: 'pim_user_filter_favorites_show', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // #2874 — the caller's own favourites, keyed by their user id below.
    #[NoPermissionRequired(reason: 'Reads the caller\'s own filter favourites.')]
    public function show(): JsonResponse
    {
        $userId = $this->requireUserId();
        $rows = $this->favorites->findByUser($userId);

        return new JsonResponse([
            'favorites' => array_map(
                static fn (UserFilterFavorite $f): array => [
                    'attribute_id' => $f->getAttribute()->getId()->toRfc4122(),
                    'code' => $f->getAttribute()->getCode(),
                    'label' => $f->getAttribute()->getLabel(),
                    'sort_order' => $f->getSortOrder(),
                ],
                $rows,
            ),
        ]);
    }

    #[Route('/api/users/me/filter-favorites', name: 'pim_user_filter_favorites_replace', methods: ['PUT', 'PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // #2874 — writes only the caller's own favourites.
    #[NoPermissionRequired(reason: 'Writes the caller\'s own filter favourites.')]
    public function replace(Request $request): JsonResponse
    {
        $userId = $this->requireUserId();

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $raw = $body['attribute_ids'] ?? null;
        if (!\is_array($raw)) {
            throw new BadRequestHttpException('attribute_ids must be an array.');
        }
        if (\count($raw) > self::MAX_FAVORITES) {
            throw new BadRequestHttpException(\sprintf(
                'attribute_ids exceeds the %d-favorite cap.',
                self::MAX_FAVORITES,
            ));
        }

        $entries = [];
        $sortOrder = 0;
        foreach ($raw as $attributeId) {
            if (!\is_string($attributeId) || '' === trim($attributeId)) {
                continue;
            }
            $attribute = $this->em->find(Attribute::class, Uuid::fromString($attributeId));
            if (!$attribute instanceof Attribute) {
                throw new BadRequestHttpException(\sprintf('Attribute %s not found.', $attributeId));
            }
            $entries[] = ['attribute_id' => $attributeId, 'sort_order' => $sortOrder];
            ++$sortOrder;
        }

        $this->favorites->replaceForUser($userId, $entries);

        return $this->show();
    }

    private function requireUserId(): Uuid
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserIdentityAware) {
            throw new BadRequestHttpException('Authenticated user required.');
        }

        return $user->getId();
    }
}
