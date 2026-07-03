<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\Bulk\BulkAddCategoryHandler;
use App\Catalog\Application\Bulk\BulkAppendValueHandler;
use App\Catalog\Application\Bulk\BulkClearAttributeHandler;
use App\Catalog\Application\Bulk\BulkDeleteHandler;
use App\Catalog\Application\Bulk\BulkDuplicateHandler;
use App\Catalog\Application\Bulk\BulkIncrementNumericHandler;
use App\Catalog\Application\Bulk\BulkMoveCategoryHandler;
use App\Catalog\Application\Bulk\BulkMultiAttributeEditHandler;
use App\Catalog\Application\Bulk\BulkPublishChannelsHandler;
use App\Catalog\Application\Bulk\BulkRemoveCategoryHandler;
use App\Catalog\Application\Bulk\BulkRemoveValueHandler;
use App\Catalog\Application\Bulk\BulkSetAttributeHandler;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * VIEW-12 (#543) — bulk actions: preview + apply.
 *
 * MVP scope: synchronous `set_attribute` action only. Async via
 * Symfony Messenger + Mercure SSE progress lands in VIEW-12.1.
 * `delete` / `duplicate` / `publish` / category ops follow in
 * VIEW-13..VIEW-16.
 *
 * Preview returns sample 5 + aggregate counts so the wizard's Step 3
 * can render the diff without persisting anything.
 */
final class BulkActionsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly CatalogObjectRepositoryInterface $catalogObjects,
        private readonly BulkSetAttributeHandler $setAttributeHandler,
        private readonly BulkClearAttributeHandler $clearAttributeHandler,
        private readonly BulkAppendValueHandler $appendValueHandler,
        private readonly BulkRemoveValueHandler $removeValueHandler,
        private readonly BulkIncrementNumericHandler $incrementNumericHandler,
        private readonly BulkMultiAttributeEditHandler $multiAttributeEditHandler,
        private readonly BulkAddCategoryHandler $addCategoryHandler,
        private readonly BulkRemoveCategoryHandler $removeCategoryHandler,
        private readonly BulkMoveCategoryHandler $moveCategoryHandler,
        private readonly BulkPublishChannelsHandler $publishChannelsHandler,
        private readonly BulkDeleteHandler $deleteHandler,
        private readonly BulkDuplicateHandler $duplicateHandler,
        private readonly BulkOperationLock $bulkLock,
    ) {
    }

    #[Route('/api/products/bulk-actions/preview', name: 'pim_bulk_actions_preview', methods: ['POST'])]
    #[Route('/api/objects/bulk-actions/preview', name: 'pim_objects_bulk_actions_preview', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'bulk_operations')]
    public function preview(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $action = $this->requireString($body, 'action');
        $targetIds = $this->requireIdList($body, 'target_ids');
        $payload = $this->requireArray($body, 'payload');

        // Hard limit 10k IDs per request (matches VIEW-11 selection cap).
        if (\count($targetIds) > 10_000) {
            throw new BadRequestHttpException('target_ids exceeds 10000 hard cap.');
        }

        $sample = [];
        $skipped = 0;
        $errors = 0;

        if (!\in_array($action, [
            'set_attribute',
            'clear_attribute',
            'append_value',
            'remove_value',
            'increment_numeric',
            'multi_attribute_edit',
            'add_category',
            'remove_category',
            'move_category',
            'publish_channels',
            'unpublish_channels',
            'delete',
            'duplicate',
        ], true)) {
            throw new BadRequestHttpException(\sprintf('Unsupported bulk action "%s" in MVP.', $action));
        }

        $sampleIds = \array_slice($targetIds, 0, 5);
        foreach ($sampleIds as $id) {
            try {
                $object = $this->catalogObjects->findById(Uuid::fromString($id));
                if (!$object instanceof CatalogObject) {
                    ++$errors;
                    continue;
                }
                [$before, $after] = $this->computePreviewDiff($action, $payload, $object);
                $sample[] = [
                    'id' => $object->getId()->toRfc4122(),
                    'sku' => $object->getCode(),
                    'before' => $before,
                    'after' => $after,
                ];
            } catch (Throwable) {
                ++$errors;
            }
        }

        return new JsonResponse([
            'action' => $action,
            'target_count' => \count($targetIds),
            'success_count' => \count($targetIds) - $skipped - $errors,
            'skipped_count' => $skipped,
            'error_count' => $errors,
            'sample' => $sample,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{mixed, mixed}
     */
    private function computePreviewDiff(string $action, array $payload, CatalogObject $object): array
    {
        $indexed = $object->getAttributesIndexed();

        if (\in_array($action, ['add_category', 'remove_category', 'move_category'], true)) {
            $categoryIds = $this->extractCategoryIds($payload);
            $before = ['categories' => '—'];
            $after = match ($action) {
                'add_category' => ['categories' => '+ '.\count($categoryIds)],
                'remove_category' => ['categories' => '− '.\count($categoryIds)],
                default => ['categories' => '= '.\count($categoryIds)],
            };

            return [$before, $after];
        }

        if ('delete' === $action) {
            return [['code' => $object->getCode()], null];
        }

        if ('duplicate' === $action) {
            return [['code' => $object->getCode()], ['code' => $object->getCode().'-COPY-N']];
        }

        if (\in_array($action, ['publish_channels', 'unpublish_channels'], true)) {
            $channels = $this->extractChannelCodes($payload);
            $publishedBefore = \is_array($indexed['published'] ?? null) ? $indexed['published'] : [];
            $publishedAfter = $publishedBefore;
            $target = 'publish_channels' === $action;
            foreach ($channels as $code) {
                $publishedAfter[$code] = $target;
            }

            return [$publishedBefore, $publishedAfter];
        }

        if ('multi_attribute_edit' === $action) {
            $edits = $payload['edits'] ?? [];
            if (!\is_array($edits)) {
                return [$indexed, $indexed];
            }
            $before = [];
            $after = [];
            foreach ($edits as $edit) {
                if (!\is_array($edit) || !\is_string($edit['attr'] ?? null) || !\is_string($edit['op'] ?? null)) {
                    continue;
                }
                $code = $edit['attr'];
                $before[$code] = $indexed[$code] ?? null;
                $after[$code] = 'clear' === $edit['op'] ? null : ($edit['value'] ?? null);
            }

            return [$before, $after];
        }

        $attrCode = $payload['attr'] ?? null;
        if (!\is_string($attrCode) || '' === $attrCode) {
            throw new BadRequestHttpException('payload.attr is required.');
        }
        $current = $indexed[$attrCode] ?? null;

        return match ($action) {
            'set_attribute' => [$current, $payload['value'] ?? null],
            'clear_attribute' => [$current, null],
            'append_value' => [$current, $this->previewAppend($current, $payload['value'] ?? null)],
            'remove_value' => [$current, $this->previewRemove($current, $payload['value'] ?? null)],
            'increment_numeric' => [$current, $this->previewIncrement(
                $current,
                \is_string($payload['operator'] ?? null) ? $payload['operator'] : '+',
                is_numeric($payload['operand'] ?? null) ? (float) $payload['operand'] : 0.0,
            )],
            default => [$current, $current],
        };
    }

    private function previewAppend(mixed $current, mixed $value): mixed
    {
        $list = match (true) {
            \is_array($current) => $current,
            null === $current => [],
            default => [$current],
        };
        if (\in_array($value, $list, true)) {
            return $list;
        }
        $list[] = $value;

        return $list;
    }

    private function previewRemove(mixed $current, mixed $value): mixed
    {
        if (\is_array($current)) {
            return array_values(array_filter($current, static fn ($v) => $v !== $value));
        }

        return $current === $value ? null : $current;
    }

    private function previewIncrement(mixed $current, string $operator, float $operand): mixed
    {
        // attributes_indexed slots are envelopes {value: X}; read the
        // scalar from inside before the arithmetic (mirrors the handler).
        $scalar = \is_array($current) ? ($current['value'] ?? null) : $current;
        if (!is_numeric($scalar)) {
            return $current;
        }
        $base = (float) $scalar;
        $result = match ($operator) {
            '+' => $base + $operand,
            '-' => $base - $operand,
            '*' => $base * $operand,
            '/' => 0.0 === $operand ? null : $base / $operand,
            '%' => 0.0 === $operand ? null : fmod($base, $operand),
            default => $base,
        };
        if (null === $result) {
            return null;
        }

        return \is_array($current) ? ['value' => $result] + $current : ['value' => $result];
    }

    #[Route('/api/products/bulk-actions/{actionType}', name: 'pim_bulk_actions_apply', methods: ['POST'])]
    #[Route('/api/objects/bulk-actions/{actionType}', name: 'pim_objects_bulk_actions_apply', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'bulk_operations')]
    public function apply(string $actionType, Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('No tenant context.');
        }
        $user = $this->security->getUser();
        $userId = $user instanceof UserIdentityAware ? $user->getId() : null;

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $targetIds = $this->requireIdList($body, 'target_ids');
        $payload = $this->requireArray($body, 'payload');

        if (\count($targetIds) > 10_000) {
            throw new BadRequestHttpException('target_ids exceeds 10000 hard cap.');
        }

        // IMP2-2.9 (#1485) — every bulk write path shares the per-tenant lock with
        // imports / bulk-edit. A collision is a 409 (RFC 7807); released in finally
        // so an exception mid-batch never leaks the lock.
        $lock = $this->bulkLock->acquire($tenant);
        if (null === $lock) {
            throw new ConflictHttpException(
                'Inna operacja masowa trwa dla tego tenanta — spróbuj ponownie po jej zakończeniu.',
            );
        }

        try {
            $session = new BulkSession(
                actionType: $actionType,
                targetObjectIds: $targetIds,
                actionPayload: $payload,
                userId: $userId,
            );
            $this->em->persist($session);
            $this->em->flush();

            $result = match ($actionType) {
                'set_attribute' => $this->setAttributeHandler->handle(
                    $session,
                    $this->requirePayloadAttr($payload),
                    $payload['value'] ?? null,
                ),
                'clear_attribute' => $this->clearAttributeHandler->handle(
                    $session,
                    $this->requirePayloadAttr($payload),
                ),
                'append_value' => $this->appendValueHandler->handle(
                    $session,
                    $this->requirePayloadAttr($payload),
                    $payload['value'] ?? null,
                ),
                'remove_value' => $this->removeValueHandler->handle(
                    $session,
                    $this->requirePayloadAttr($payload),
                    $payload['value'] ?? null,
                ),
                'increment_numeric' => $this->incrementNumericHandler->handle(
                    $session,
                    $this->requirePayloadAttr($payload),
                    \is_string($payload['operator'] ?? null) ? $payload['operator'] : '+',
                    is_numeric($payload['operand'] ?? null) ? (float) $payload['operand'] : 0.0,
                ),
                'multi_attribute_edit' => $this->multiAttributeEditHandler->handle(
                    $session,
                    $this->normaliseEdits($payload['edits'] ?? null),
                ),
                'add_category' => $this->addCategoryHandler->handle(
                    $session,
                    $this->extractCategoryIds($payload),
                ),
                'remove_category' => $this->removeCategoryHandler->handle(
                    $session,
                    $this->extractCategoryIds($payload),
                ),
                'move_category' => $this->moveCategoryHandler->handle(
                    $session,
                    $this->extractCategoryIds($payload),
                ),
                'publish_channels' => $this->publishChannelsHandler->handle(
                    $session,
                    $this->extractChannelCodes($payload),
                    true,
                ),
                'unpublish_channels' => $this->publishChannelsHandler->handle(
                    $session,
                    $this->extractChannelCodes($payload),
                    false,
                ),
                'delete' => $this->dispatchDelete($session, $body, $targetIds),
                'duplicate' => $this->duplicateHandler->handle($session),
                default => throw new NotFoundHttpException(\sprintf('Bulk action "%s" not implemented.', $actionType)),
            };

            return new JsonResponse([
                'session_id' => $session->getId()->toRfc4122(),
                'action' => $actionType,
                'target_count' => $session->getTargetCount(),
                'success_count' => $result['success'],
                'skipped_count' => $result['skipped'],
                'error_count' => $result['error'],
                'rollback_available_until' => $session->getRollbackAvailableUntil()?->format(DateTimeInterface::ATOM),
                'completed_at' => $session->getCompletedAt()?->format(DateTimeInterface::ATOM),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requirePayloadAttr(array $payload): string
    {
        $code = $payload['attr'] ?? null;
        if (!\is_string($code) || '' === trim($code)) {
            throw new BadRequestHttpException('payload.attr is required.');
        }

        return trim($code);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function extractCategoryIds(array $payload): array
    {
        $rawIds = $payload['category_ids'] ?? null;
        if (\is_array($rawIds) && [] !== $rawIds) {
            $out = [];
            foreach ($rawIds as $id) {
                if (\is_string($id) && '' !== $id) {
                    $out[] = $id;
                }
            }
            if ([] !== $out) {
                return $out;
            }
        }

        // #2161 — the Cmd+K planner emits human-friendly category_codes
        // ("dodaj kategorię botki"), not UUIDs. Resolve each token to a
        // category object id by CODE or display NAME (case-insensitive), so
        // the quick action actually applies instead of returning a 400.
        $rawCodes = $payload['category_codes'] ?? null;
        if (\is_array($rawCodes) && [] !== $rawCodes) {
            $codes = [];
            foreach ($rawCodes as $code) {
                if (\is_string($code) && '' !== trim($code)) {
                    $codes[] = trim($code);
                }
            }
            if ([] !== $codes) {
                return $this->resolveCategoryRefs($codes);
            }
        }

        throw new BadRequestHttpException('payload.category_ids (or category_codes) must be a non-empty array.');
    }

    /**
     * Resolve category references (code or display name, case-insensitive)
     * to category object ids within the current tenant. Unknown references
     * are a clear 404 naming them, never a silent drop.
     *
     * @param list<string> $refs
     *
     * @return list<string>
     */
    private function resolveCategoryRefs(array $refs): array
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('No tenant context.');
        }

        $lowered = array_values(array_unique(array_map('mb_strtolower', $refs)));

        // tenant-safe: explicit tenant_id predicate; RLS is the backstop.
        // Match code OR name (scalar envelope value + pl/en localized value).
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT co.id, co.code, co.attributes_indexed->'name' AS name
             FROM objects co
             JOIN object_types ot ON ot.id = co.object_type_id
             WHERE co.tenant_id = :tenant AND ot.kind = 'category'",
            ['tenant' => $tenant->getId()->toRfc4122()],
        );

        $byRef = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!\is_string($id)) {
                continue;
            }
            foreach ($this->categoryRefKeys($row) as $key) {
                // First match wins; codes/names are expected unique per tenant.
                $byRef[$key] ??= $id;
            }
        }

        $ids = [];
        $missing = [];
        foreach ($lowered as $ref) {
            if (isset($byRef[$ref])) {
                $ids[] = $byRef[$ref];
            } else {
                $missing[] = $ref;
            }
        }
        if ([] !== $missing) {
            throw new NotFoundHttpException(\sprintf('Nie znaleziono kategorii: %s.', implode(', ', $missing)));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Lower-cased lookup keys for a category row: its code and every display
     * name shape (scalar envelope value, and pl/en of a localized value).
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function categoryRefKeys(array $row): array
    {
        $keys = [];
        $code = $row['code'] ?? null;
        if (\is_string($code) && '' !== $code) {
            $keys[] = mb_strtolower($code);
        }

        $nameRaw = $row['name'] ?? null;
        $name = \is_string($nameRaw) ? json_decode($nameRaw, true) : $nameRaw;
        $value = \is_array($name) ? ($name['value'] ?? null) : $name;
        if (\is_string($value) && '' !== $value) {
            $keys[] = mb_strtolower($value);
        } elseif (\is_array($value)) {
            foreach ($value as $localized) {
                if (\is_string($localized) && '' !== $localized) {
                    $keys[] = mb_strtolower($localized);
                }
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $targetIds
     *
     * @return array{success: int, skipped: int, error: int}
     */
    private function dispatchDelete(BulkSession $session, array $body, array $targetIds): array
    {
        $confirmationCount = $body['confirmation_count'] ?? null;
        if (!\is_int($confirmationCount) || $confirmationCount !== \count($targetIds)) {
            throw new BadRequestHttpException(
                'confirmation_count must equal the target count for destructive actions.',
            );
        }

        return $this->deleteHandler->handle($session);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function extractChannelCodes(array $payload): array
    {
        $raw = $payload['channel_codes'] ?? null;
        if (!\is_array($raw) || [] === $raw) {
            throw new BadRequestHttpException('payload.channel_codes must be a non-empty array.');
        }
        $out = [];
        foreach ($raw as $code) {
            if (\is_string($code) && '' !== $code) {
                $out[] = $code;
            }
        }
        if ([] === $out) {
            throw new BadRequestHttpException('payload.channel_codes has no valid entries.');
        }

        return $out;
    }

    /**
     * @return list<array{attr: string, op: string, value?: mixed}>
     */
    private function normaliseEdits(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new BadRequestHttpException('payload.edits must be a non-empty array.');
        }
        $out = [];
        foreach ($raw as $edit) {
            if (!\is_array($edit)) {
                continue;
            }
            $code = $edit['attr'] ?? null;
            $op = $edit['op'] ?? null;
            if (!\is_string($code) || '' === $code || !\is_string($op)) {
                continue;
            }
            $entry = ['attr' => $code, 'op' => $op];
            if (\array_key_exists('value', $edit)) {
                $entry['value'] = $edit['value'];
            }
            $out[] = $entry;
        }
        if ([] === $out) {
            throw new BadRequestHttpException('payload.edits has no valid entries.');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requireString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new BadRequestHttpException(\sprintf('%s must be a non-empty string.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function requireIdList(array $body, string $key): array
    {
        $raw = $body[$key] ?? null;
        if (!\is_array($raw) || [] === $raw) {
            throw new BadRequestHttpException(\sprintf('%s must be a non-empty array.', $key));
        }
        $out = [];
        foreach ($raw as $id) {
            if (\is_string($id) && '' !== $id) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function requireArray(array $body, string $key): array
    {
        $raw = $body[$key] ?? null;
        if (!\is_array($raw)) {
            throw new BadRequestHttpException(\sprintf('%s must be an object.', $key));
        }
        /** @var array<string, mixed> $typed */
        $typed = $raw;

        return $typed;
    }
}
