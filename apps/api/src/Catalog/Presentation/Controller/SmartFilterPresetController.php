<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Domain\Entity\SmartFilterPreset;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * VIEW-09 (#535) — `SmartFilterPreset` CRUD endpoints.
 *
 * Read scope:
 *  - system-shipped (tenant_id IS NULL) — visible to every authenticated user.
 *  - tenant-shared (user_id IS NULL, tenant_id matches) — Faza 1+ lane.
 *  - user-owned (user_id matches current user) — visible to owner only.
 *
 * Write scope (PATCH / DELETE):
 *  - built-in (is_built_in=true) → 403 Conflict, immutable per CLAUDE.md ADR.
 *  - user-owned → owner only.
 *  - cross-user attempt → 404 (information hiding, not 403).
 *
 * Marketing nota PRD §11 — "smart" tutaj znaczy *rule-based*, nie LLM.
 */
final class SmartFilterPresetController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const int MAX_USER_PRESETS = 50;
    private const int MAX_NAME_LEN = 60;
    private const int MIN_NAME_LEN = 3;
    private const int MAX_COLUMNS = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly FilterDslResolver $filterDslResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/smart-filter-presets', name: 'pim_smart_filter_presets_list', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function list(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        $user = $this->security->getUser();
        $userId = $user instanceof UserIdentityAware ? $user->getId() : null;

        $withCounts = $request->query->getBoolean('counts', false);
        // UP-05 (#1020) — scope presets per ObjectType.code (mirrors
        // /api/saved-views?resource=). When absent, fall back to 'products'
        // for backward compatibility with the legacy product list.
        $requestedResource = $request->query->getString('resource', 'products');
        $resource = '' === $requestedResource ? 'products' : $requestedResource;

        $qb = $this->em->getRepository(SmartFilterPreset::class)
            ->createQueryBuilder('p')
            ->orderBy('p.isBuiltIn', 'DESC')
            ->addOrderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->setMaxResults(self::MAX_USER_PRESETS + 5); // 5 built-in + N user

        // Visible to user: system-shipped (tenant NULL) OR own user-defined.
        if (null !== $tenant && null !== $userId) {
            $qb->andWhere('p.tenant IS NULL OR (p.tenant = :tenant AND (p.userId = :user OR p.userId IS NULL))')
                ->setParameter('tenant', $tenant)
                ->setParameter('user', $userId);
        } else {
            $qb->andWhere('p.tenant IS NULL');
        }

        // Resource scope: matching the requested resource OR cross-kind
        // (resource IS NULL) presets — same semantic as `saved_views.resource`.
        $qb->andWhere('p.resource = :resource OR p.resource IS NULL')
            ->setParameter('resource', $resource);

        /** @var list<SmartFilterPreset> $presets */
        $presets = $qb->getQuery()->getResult();

        $counts = $withCounts ? $this->resolveCounts($presets) : null;

        return new JsonResponse([
            'data' => array_map(
                fn (SmartFilterPreset $p): array => $this->serialize($p, $counts[$p->getId()->toRfc4122()] ?? null),
                $presets,
            ),
        ]);
    }

    #[Route('/api/smart-filter-presets', name: 'pim_smart_filter_presets_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function create(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('No tenant context.');
        }
        $user = $this->security->getUser();
        if (!$user instanceof UserIdentityAware) {
            throw new BadRequestHttpException('User identity required.');
        }
        $userId = $user->getId();

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $name = $this->parseName($body['name'] ?? null);
        $icon = $this->parseIcon($body['icon'] ?? null);
        $query = $this->parseQuery($body['query'] ?? null);
        $sortOrderRaw = $body['sort_order'] ?? 100;
        $sortOrder = is_numeric($sortOrderRaw) ? (int) $sortOrderRaw : 100;
        // UP-05 (#1020) — scope user-created presets to the resource they
        // were created from (e.g. `samochody`). Defaults to `products` for
        // backward compatibility with the legacy product list.
        $resourceRaw = $body['resource'] ?? 'products';
        $resource = \is_string($resourceRaw) && '' !== $resourceRaw ? $resourceRaw : 'products';
        // PTR-01 — optional column snapshot merged into the preset (the old
        // "saved view" role); null keeps the legacy filter-only shape.
        $columns = $this->parseColumns($body['columns'] ?? null);

        $slug = $this->generateUniqueSlug($name['pl'], $tenant->getId(), $userId);

        $preset = new SmartFilterPreset(
            slug: $slug,
            name: $name,
            icon: $icon,
            query: $query,
            userId: $userId,
            isBuiltIn: false,
            sortOrder: $sortOrder,
            resource: $resource,
            columns: $columns,
        );

        $this->em->persist($preset);
        $this->em->flush();

        return new JsonResponse($this->serialize($preset), Response::HTTP_CREATED);
    }

    #[Route('/api/smart-filter-presets/{id}', name: 'pim_smart_filter_presets_patch', requirements: ['id' => self::UUID_REGEX], methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function patch(string $id, Request $request): JsonResponse
    {
        $preset = $this->mustFindOwned($id);

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('name', $body)) {
            $preset->rename($this->parseName($body['name']));
        }
        if (\array_key_exists('icon', $body)) {
            $preset->changeIcon($this->parseIcon($body['icon']));
        }
        if (\array_key_exists('query', $body)) {
            $preset->updateQuery($this->parseQuery($body['query']));
        }
        if (\array_key_exists('columns', $body)) {
            $preset->updateColumns($this->parseColumns($body['columns']));
        }
        if (\array_key_exists('sort_order', $body) && is_numeric($body['sort_order'])) {
            $preset->reorder((int) $body['sort_order']);
        }

        $this->em->flush();

        return new JsonResponse($this->serialize($preset));
    }

    #[Route('/api/smart-filter-presets/{id}', name: 'pim_smart_filter_presets_delete', requirements: ['id' => self::UUID_REGEX], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function delete(string $id): Response
    {
        $preset = $this->mustFindOwned($id);
        $this->em->remove($preset);
        $this->em->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function mustFindOwned(string $id): SmartFilterPreset
    {
        $preset = $this->em->getRepository(SmartFilterPreset::class)->find(Uuid::fromString($id));
        if (!$preset instanceof SmartFilterPreset) {
            throw new NotFoundHttpException(\sprintf('Smart filter preset %s not found.', $id));
        }

        if ($preset->isBuiltIn()) {
            throw new AccessDeniedHttpException('Built-in smart filter presets are immutable.');
        }

        $user = $this->security->getUser();
        $userId = $user instanceof UserIdentityAware ? $user->getId() : null;
        $tenant = $this->tenantContext->get();

        $sameTenant = null !== $tenant && null !== $preset->getTenant()
            && $preset->getTenant()->getId()->equals($tenant->getId());

        if (!$sameTenant) {
            // Cross-tenant — pretend not found (information hiding).
            throw new NotFoundHttpException(\sprintf('Smart filter preset %s not found.', $id));
        }

        if (null !== $userId && !$preset->isOwnedBy($userId)) {
            throw new NotFoundHttpException(\sprintf('Smart filter preset %s not found.', $id));
        }

        return $preset;
    }

    /**
     * @return array{pl: string, en: string}
     */
    private function parseName(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new BadRequestHttpException('name must be an object {pl, en}.');
        }
        $pl = $raw['pl'] ?? null;
        $en = $raw['en'] ?? null;
        if (!\is_string($pl) || !\is_string($en)) {
            throw new BadRequestHttpException('name must include both pl and en strings.');
        }
        $pl = trim($pl);
        $en = trim($en);
        if (\strlen($pl) < self::MIN_NAME_LEN || \strlen($en) < self::MIN_NAME_LEN) {
            throw new BadRequestHttpException(\sprintf('name must be at least %d characters in each locale.', self::MIN_NAME_LEN));
        }
        if (mb_strlen($pl) > self::MAX_NAME_LEN || mb_strlen($en) > self::MAX_NAME_LEN) {
            throw new BadRequestHttpException(\sprintf('name must be at most %d characters in each locale.', self::MAX_NAME_LEN));
        }

        return ['pl' => $pl, 'en' => $en];
    }

    private function parseIcon(mixed $raw): string
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            throw new BadRequestHttpException('icon is required (emoji string or lucide icon name).');
        }
        $icon = trim($raw);
        if (mb_strlen($icon) > 64) {
            throw new BadRequestHttpException('icon must be at most 64 characters.');
        }

        return $icon;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseQuery(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new BadRequestHttpException('query must be a FilterDsl object.');
        }
        /** @var array<string, mixed> $typed */
        $typed = $raw;
        $this->filterDslResolver->validate($typed);

        return $typed;
    }

    /**
     * PTR-01 — validate the optional column snapshot. Accepts null (no column
     * preferences) or a list of GridColumnOverride entries
     * {key: string, hidden?: bool, width?: int, position?: int}. Unknown keys
     * are dropped; a malformed payload is a 400 rather than silent corruption.
     *
     * @return list<array<string, mixed>>|null
     */
    private function parseColumns(mixed $raw): ?array
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new BadRequestHttpException('columns must be a list of {key, hidden?, width?, position?}.');
        }
        if (\count($raw) > self::MAX_COLUMNS) {
            throw new BadRequestHttpException(\sprintf('columns must contain at most %d entries.', self::MAX_COLUMNS));
        }

        $out = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry) || !isset($entry['key']) || !\is_string($entry['key']) || '' === $entry['key']) {
                throw new BadRequestHttpException('each column entry must include a non-empty string "key".');
            }
            $normalized = ['key' => $entry['key']];
            if (\array_key_exists('hidden', $entry)) {
                $normalized['hidden'] = (bool) $entry['hidden'];
            }
            if (isset($entry['width']) && is_numeric($entry['width'])) {
                $normalized['width'] = (int) $entry['width'];
            }
            if (isset($entry['position']) && is_numeric($entry['position'])) {
                $normalized['position'] = (int) $entry['position'];
            }
            $out[] = $normalized;
        }

        return $out;
    }

    private function generateUniqueSlug(string $name, Uuid $tenantId, Uuid $userId): string
    {
        $base = $this->slugify($name);
        if ('' === $base) {
            $base = 'preset';
        }
        $candidate = $base;
        $counter = 1;
        $repo = $this->em->getRepository(SmartFilterPreset::class);
        while (null !== $repo->findOneBy([
            'tenant' => $tenantId,
            'userId' => $userId,
            'slug' => $candidate,
        ])) {
            ++$counter;
            $candidate = $base.'-'.$counter;
            if ($counter > 9999) {
                throw new ConflictHttpException(\sprintf('Cannot allocate a slug for "%s".', $name));
            }
        }

        return $candidate;
    }

    private function slugify(string $name): string
    {
        $lower = mb_strtolower($name);
        // Replace Polish diacritics + non-alnum with hyphen.
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];
        $ascii = strtr($lower, $map);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $ascii) ?? '';

        return trim($slug, '-');
    }

    /**
     * Single batched COUNT query for all presets (no N+1). Returns
     * `[presetId.toRfc4122 => count]`. Counts only run against the
     * current tenant scope — system-shipped presets are surfaced with
     * the same count for every viewer (cheap, cache-friendly).
     *
     * #3034 — this used to query `catalog_objects`, a table that does not
     * exist (the table is `objects`), and to omit the `co` alias that the
     * fragments from {@see FilterDslResolver} reference. Every call therefore
     * raised `relation "catalog_objects" does not exist`, which the bare
     * `catch (Throwable)` swallowed into a 0 — so in production every preset
     * chip showed "0" and nobody could tell. The catch now logs before it
     * degrades, so the next such breakage is visible in the logs.
     *
     * @param list<SmartFilterPreset> $presets
     *
     * @return array<string, int>
     */
    private function resolveCounts(array $presets): array
    {
        if ([] === $presets) {
            return [];
        }

        $tenant = $this->tenantContext->get();
        $tenantId = $tenant?->getId()->toRfc4122();
        if (null === $tenantId) {
            return [];
        }

        // Compile every DSL first. A preset targeting attributes that are not
        // indexed compiles to null and is reported as 0 without touching SQL.
        $fragments = [];
        $counts = [];
        foreach ($presets as $preset) {
            $id = $preset->getId()->toRfc4122();
            $counts[$id] = 0;
            $sql = $this->filterDslResolver->toCountSql($preset->getQuery());
            if (null !== $sql) {
                $fragments[$id] = $sql;
            }
        }

        if ([] === $fragments) {
            return $counts;
        }

        $batched = $this->countPresetsInOneScan($fragments, $tenantId);
        if (null !== $batched) {
            return array_replace($counts, $batched);
        }

        // One malformed fragment aborts the combined statement, so fall back
        // to isolated queries rather than zeroing every preset at once.
        foreach ($fragments as $id => $sql) {
            $counts[$id] = $this->countOnePreset($sql, $tenantId, $id);
        }

        return $counts;
    }

    /**
     * Count every preset in ONE pass over `objects` using conditional
     * aggregates, instead of one sequential scan per preset. On the production
     * catalogue a single such scan costs ~570 ms, so six presets in a loop
     * would have added ~3.4 s to the product list — trading a silent bug for
     * a visible stall.
     *
     * Returns null when the combined statement fails, signalling the caller
     * to retry preset by preset.
     *
     * @param array<string, string> $fragments presetId => parameter-free WHERE fragment
     *
     * @return array<string, int>|null
     */
    private function countPresetsInOneScan(array $fragments, string $tenantId): ?array
    {
        $selects = [];
        $aliases = [];
        $index = 0;
        foreach ($fragments as $id => $sql) {
            $alias = 'preset_count_'.$index;
            $aliases[$alias] = $id;
            $selects[] = \sprintf('COUNT(*) FILTER (WHERE (%s)) AS %s', $sql, $alias);
            ++$index;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT '.implode(', ', $selects)
                .' FROM objects co WHERE co.tenant_id = :tenant AND co.kind = :kind',
                ['tenant' => $tenantId, 'kind' => 'product'],
            );
        } catch (Throwable $error) {
            $this->logger->warning(
                'Batched smart-filter preset count failed; retrying preset by preset.',
                ['exception' => $error],
            );

            return null;
        }

        if (false === $row) {
            return null;
        }

        $out = [];
        foreach ($aliases as $alias => $id) {
            $value = $row[$alias] ?? null;
            $out[$id] = is_numeric($value) ? (int) $value : 0;
        }

        return $out;
    }

    private function countOnePreset(string $sql, string $tenantId, string $presetId): int
    {
        try {
            // tenant-safe: explicit tenant_id filter
            $result = $this->connection->executeQuery(
                'SELECT COUNT(*) FROM objects co WHERE co.tenant_id = :tenant AND co.kind = :kind AND ('.$sql.')',
                ['tenant' => $tenantId, 'kind' => 'product'],
            )->fetchOne();

            return is_numeric($result) ? (int) $result : 0;
        } catch (Throwable $error) {
            // Degrade to 0 rather than failing the whole list endpoint — but
            // say so, because a silent 0 here hid a broken query for months.
            $this->logger->warning('Smart-filter preset count failed.', [
                'preset_id' => $presetId,
                'exception' => $error,
            ]);

            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(SmartFilterPreset $preset, ?int $count = null): array
    {
        $payload = [
            'id' => $preset->getId()->toRfc4122(),
            'slug' => $preset->getSlug(),
            'name' => $preset->getName(),
            'icon' => $preset->getIcon(),
            'query' => $preset->getQuery(),
            'columns' => $preset->getColumns(),
            'is_built_in' => $preset->isBuiltIn(),
            'is_system' => $preset->isSystem(),
            'sort_order' => $preset->getSortOrder(),
            'resource' => $preset->getResource(),
            'created_at' => $preset->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $preset->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];

        if (null !== $count) {
            $payload['count'] = $count;
        }

        return $payload;
    }
}
