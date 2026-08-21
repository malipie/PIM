<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const STR_PAD_LEFT;

/**
 * #2943 — `GET /api/object_types/{id}/next-code` suggests the next free
 * identifier for a new object of this type.
 *
 * The create form asks for an "ID" before it will save anything. For a
 * product that is the SKU the operator already has; for a custom module
 * ("Twórcy" in the report) there is no external number to copy, so every
 * new row meant inventing one. The suggestion is a **prefill, not a
 * reservation** — the operator overwrites it whenever the identifier comes
 * from somewhere real (ERP, supplier), which is why nothing is stored here
 * and two operators can be offered the same number.
 *
 * That race is the caller's to handle: creating with a taken code answers
 * 409, and the form asks again. A counter table would serialise creation
 * across the whole tenant to save a retry that costs one request.
 *
 * Shape: `{code}_000001`, continuing from the highest number already used
 * under that prefix rather than from the row count — deleting object 7 of 7
 * must not hand out `_000007` again while it still exists in an export or
 * someone's spreadsheet.
 */
final class ObjectTypeNextCodeController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private const int PAD_LENGTH = 6;

    public function __construct(
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly Connection $connection,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/api/object_types/{id}/next-code',
        name: 'pim_object_types_next_code',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'object', action: 'add', anyOf: [
        'object.add',
        'products.add',
        'categories.add_edit',
        'multimedia.add_edit_own',
        'multimedia.add_edit_any',
    ])]
    public function __invoke(string $id): JsonResponse
    {
        $objectType = $this->objectTypes->findById(Uuid::fromString($id));
        if (null === $objectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $id));
        }

        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('Tenant context is required.');
        }

        $prefix = $objectType->getCode().'_';

        // Native SQL: the TenantFilter does not cover it, so the tenant is
        // scoped explicitly. Only codes shaped `<prefix><digits>` count —
        // an operator-supplied `TW-LEM` must not be read as a number.
        $highest = $this->connection->fetchOne(
            <<<'SQL'
                SELECT MAX(CAST(SUBSTRING(o.code FROM CHAR_LENGTH(:prefix) + 1) AS BIGINT))
                FROM objects o
                WHERE o.object_type_id = :objectTypeId
                  AND o.tenant_id = :tenantId
                  AND o.code LIKE :like
                  AND SUBSTRING(o.code FROM CHAR_LENGTH(:prefix) + 1) ~ '^[0-9]+$'
                SQL,
            [
                'prefix' => $prefix,
                'objectTypeId' => $objectType->getId()->toRfc4122(),
                'tenantId' => $tenant->getId()->toRfc4122(),
                'like' => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%',
            ],
        );

        $next = (\is_int($highest) || (\is_string($highest) && '' !== $highest)) ? ((int) $highest) + 1 : 1;

        return new JsonResponse([
            'objectTypeId' => $objectType->getId()->toRfc4122(),
            'code' => $prefix.str_pad((string) $next, self::PAD_LENGTH, '0', STR_PAD_LEFT),
        ]);
    }
}
