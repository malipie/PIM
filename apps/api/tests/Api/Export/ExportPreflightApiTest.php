<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Channel;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * EXR-07 (#1383) — preflight count + sync/async routing contract.
 */
final class ExportPreflightApiTest extends CatalogApiTestCase
{
    #[Test]
    public function countsAllObjectsOfACustomModuleInSyncMode(): void
    {
        $tenant = $this->tenant();
        $services = $this->customObjectType($tenant, 'services');
        $this->object($tenant, $services, 'SRV-1');
        $this->object($tenant, $services, 'SRV-2');
        $this->object($tenant, $services, 'SRV-3');
        $this->em()->flush();

        $body = $this->preflight([
            'entity_type' => 'custom_module',
            'object_type_id' => $services->getId()->toRfc4122(),
            'target_scope' => 'all',
        ]);

        self::assertSame(3, $body['count']);
        self::assertSame('sync', $body['mode']);
        self::assertSame(100, $body['threshold']);
        self::assertSame(100000, $body['soft_cap']);
        self::assertFalse($body['exceeds_cap']);
    }

    #[Test]
    public function modeFlipsToAsyncAtThreshold(): void
    {
        $tenant = $this->tenant();
        $bulk = $this->customObjectType($tenant, 'bulk');
        for ($i = 1; $i <= 100; ++$i) {
            $this->object($tenant, $bulk, sprintf('BULK-%03d', $i));
        }
        $this->em()->flush();

        $body = $this->preflight([
            'entity_type' => 'custom_module',
            'object_type_id' => $bulk->getId()->toRfc4122(),
            'target_scope' => 'all',
        ]);

        self::assertSame(100, $body['count']);
        self::assertSame('async', $body['mode']);
        self::assertFalse($body['exceeds_cap']);
    }

    #[Test]
    public function filterCountIsScopedToTheObjectType(): void
    {
        $tenant = $this->tenant();
        $services = $this->customObjectType($tenant, 'filtered-services');
        $this->object($tenant, $services, 'F-1', ['brand' => 'Festo']);
        $this->object($tenant, $services, 'F-2', ['brand' => 'Festo']);
        $this->object($tenant, $services, 'B-1', ['brand' => 'Bosch']);

        // A Festo object under a DIFFERENT ObjectType must not be counted.
        $other = $this->customObjectType($tenant, 'other-services');
        $this->object($tenant, $other, 'X-1', ['brand' => 'Festo']);
        $this->em()->flush();

        $body = $this->preflight([
            'entity_type' => 'custom_module',
            'object_type_id' => $services->getId()->toRfc4122(),
            'target_scope' => 'filter',
            'filter' => [
                'operator' => 'AND',
                'conditions' => [
                    ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'],
                ],
            ],
        ]);

        self::assertSame(2, $body['count']);
        self::assertSame('sync', $body['mode']);
    }

    #[Test]
    public function filterCountIsScopedByEditorialStatus(): void
    {
        // #2526 — `status` is a filterable system field, so an export scoped
        // to `status = published` counts (and later exports) only published
        // objects. This is the whole point: the operator decides via the
        // advanced filter what leaves the PIM, per editorial state.
        $tenant = $this->tenant();
        $type = $this->customObjectType($tenant, 'status-scoped');
        $this->object($tenant, $type, 'S-PUB-1', status: CatalogObject::STATUS_PUBLISHED);
        $this->object($tenant, $type, 'S-PUB-2', status: CatalogObject::STATUS_PUBLISHED);
        $this->object($tenant, $type, 'S-DRAFT-1', status: CatalogObject::STATUS_DRAFT);
        $this->em()->flush();

        $body = $this->preflight([
            'entity_type' => 'custom_module',
            'object_type_id' => $type->getId()->toRfc4122(),
            'target_scope' => 'filter',
            'filter' => ['attr' => 'status', 'op' => '=', 'value' => 'published'],
        ]);

        self::assertSame(2, $body['count']);
    }

    #[Test]
    public function rejectsFilterWithInvalidOperatorBeforeRunningSql(): void
    {
        // AUD-031 / W2-3 (C-2) — countFilter previously compiled the DSL
        // straight to SQL without validate(). An operator that is not in the
        // known set must be rejected at validation (400) before the literal
        // ever reaches the COUNT query.
        $tenant = $this->tenant();
        $services = $this->customObjectType($tenant, 'invalid-op-services');
        $this->object($tenant, $services, 'IO-1', ['brand' => 'Festo']);
        $this->em()->flush();

        $response = $this->preflightRaw([
            'entity_type' => 'custom_module',
            'object_type_id' => $services->getId()->toRfc4122(),
            'target_scope' => 'filter',
            'filter' => ['attr' => 'brand', 'op' => 'REGEX MATCH', 'value' => '^F.*$'],
        ]);

        self::assertSame(400, $response);
    }

    #[Test]
    public function rejectsOversizedFilterGroupBeforeRunningSql(): void
    {
        // AUD-031 / W2-3 (C-2) — the genuine behavioural gap closed by calling
        // validate() in countFilter. A group with >20 conditions COMPILES to
        // valid SQL (the compiler caps only nesting depth, not condition
        // count), so before the fix this reached the DB and returned HTTP 200
        // with a real count. validate() rejects it up front (400), matching
        // every other DSL entry point (SmartFilterPresetController).
        $tenant = $this->tenant();
        $services = $this->customObjectType($tenant, 'oversized-group-services');
        $this->object($tenant, $services, 'OG-1', ['brand' => 'Festo']);
        $this->em()->flush();

        $conditions = [];
        for ($i = 0; $i < 21; ++$i) {
            $conditions[] = ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'];
        }

        $response = $this->preflightRaw([
            'entity_type' => 'custom_module',
            'object_type_id' => $services->getId()->toRfc4122(),
            'target_scope' => 'filter',
            'filter' => ['operator' => 'AND', 'conditions' => $conditions],
        ]);

        self::assertSame(400, $response);
    }

    #[Test]
    public function rejectsFilterWithUnsafeAttributeIdentifierBeforeRunningSql(): void
    {
        // An attribute identifier carrying SQL metacharacters must be
        // rejected by validate() (safeIdent regex) with a 400, not silently
        // dropped (toCountSql -> null path) after bypassing validation.
        $tenant = $this->tenant();
        $services = $this->customObjectType($tenant, 'unsafe-ident-services');
        $this->object($tenant, $services, 'UI-1', ['brand' => 'Festo']);
        $this->em()->flush();

        $response = $this->preflightRaw([
            'entity_type' => 'custom_module',
            'object_type_id' => $services->getId()->toRfc4122(),
            'target_scope' => 'filter',
            'filter' => ['attr' => "brand'; DROP TABLE objects; --", 'op' => '=', 'value' => 'x'],
        ]);

        self::assertSame(400, $response);
    }

    #[Test]
    public function adversarialOrInjectionValueDoesNotMatchEveryRow(): void
    {
        // AUD-031 (C-2) adversarial: a classic `x' OR '1'='1` payload as the
        // filter VALUE must be treated as a single escaped string literal,
        // matching only rows whose brand literally equals that string (none),
        // never every row in the ObjectType.
        $tenant = $this->tenant();
        $services = $this->customObjectType($tenant, 'adversarial-services');
        $this->object($tenant, $services, 'A-1', ['brand' => 'Festo']);
        $this->object($tenant, $services, 'A-2', ['brand' => 'Bosch']);
        $this->object($tenant, $services, 'A-3', ['brand' => 'Siemens']);
        $this->em()->flush();

        $body = $this->preflight([
            'entity_type' => 'custom_module',
            'object_type_id' => $services->getId()->toRfc4122(),
            'target_scope' => 'filter',
            'filter' => ['attr' => 'brand', 'op' => '=', 'value' => "x' OR '1'='1"],
        ]);

        // Escaping holds: no brand equals the literal payload, so 0 rows —
        // categorically NOT the 3 rows an `OR '1'='1' would have leaked.
        self::assertSame(0, $body['count']);
    }

    #[Test]
    public function selectedTreeScopeMatchesPreflightAndFinalFile(): void
    {
        $tenant = $this->tenant();
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($type instanceof ObjectType);

        $first = new CatalogObject($type, 'SCOPE-MASTER-1');
        $first->assignTenant($tenant);
        $second = new CatalogObject($type, 'SCOPE-MASTER-2');
        $second->assignTenant($tenant);
        $variant = new CatalogObject($type, 'SCOPE-VARIANT-1');
        $variant->assignTenant($tenant);
        $variant->assignParent($first);
        $this->em()->persist($first);
        $this->em()->persist($second);
        $this->em()->persist($variant);
        $this->em()->flush();

        $selectedIds = [
            $first->getId()->toRfc4122(),
            $first->getId()->toRfc4122(),
            $second->getId()->toRfc4122(),
        ];
        $body = $this->preflight([
            'entity_type' => 'product',
            'target_scope' => 'selected',
            'selected_object_ids' => $selectedIds,
            'include_variants' => false,
        ]);

        self::assertSame(2, $body['count']);
        self::assertSame('sync', $body['mode']);

        $response = $this->authenticatedClient()->request('POST', '/api/products/export', [
            'json' => [
                'entity_type' => 'product',
                'format' => 'xml',
                'target_scope' => 'selected',
                'selected_columns' => ['sku'],
                'selected_object_ids' => $selectedIds,
                'include_variants' => false,
            ],
        ]);
        self::assertSame(200, $response->getStatusCode());

        // BinaryFileResponse deletes its temp file after the test kernel sends
        // it, so assert the persisted runner counters (the same values used by
        // session history) rather than trying to reopen the deleted body.
        $run = $this->em()->getConnection()->fetchAssociative(
            'SELECT target_count, success_count FROM export_sessions ORDER BY started_at DESC LIMIT 1',
        );
        self::assertIsArray($run);
        self::assertIsInt($body['count']);
        $targetCount = $run['target_count'];
        $successCount = $run['success_count'];
        self::assertTrue(\is_int($targetCount) || \is_string($targetCount));
        self::assertTrue(\is_int($successCount) || \is_string($successCount));
        self::assertSame($body['count'], (int) $targetCount);
        self::assertSame($body['count'], (int) $successCount, 'preflight scope must equal rows written to the file');
    }

    #[Test]
    public function productAllCountIsZeroOnEmptyCatalog(): void
    {
        $body = $this->preflight([
            'entity_type' => 'product',
            'target_scope' => 'all',
        ]);

        self::assertSame(0, $body['count']);
        self::assertSame('sync', $body['mode']);
    }

    #[Test]
    public function countsStructuralEntityType(): void
    {
        // EXR-06 enabled structural preflight counts (was 422 in EXR-07).
        $response = $this->preflightRaw([
            'entity_type' => 'module_schema',
            'target_scope' => 'all',
        ]);

        self::assertSame(200, $response);
    }

    #[Test]
    public function rejectsCustomModuleWithoutObjectTypeId(): void
    {
        $response = $this->preflightRaw([
            'entity_type' => 'custom_module',
            'target_scope' => 'all',
        ]);

        self::assertSame(422, $response);
    }

    #[Test]
    public function rejectsChannelColumnWhoseChannelDoesNotResolve(): void
    {
        // IMP2-1.6 (#1469, R-47) — a `price.ghost` column referencing a
        // channel that no longer exists must 422 at preflight, not silently
        // export a blank column a clear_if_empty could weaponise.
        $response = $this->preflightRaw([
            'entity_type' => 'product',
            'target_scope' => 'all',
            'selected_columns' => ['sku', 'price.ghost'],
            'channels' => ['ghost'],
        ]);

        self::assertSame(422, $response);
    }

    #[Test]
    public function acceptsChannelColumnWhenChannelResolves(): void
    {
        // The same shape passes once the channel exists in the tenant.
        $tenant = $this->tenant();
        $channel = new Channel('shopify', 'Shopify');
        $channel->assignTenant($tenant);
        $this->em()->persist($channel);
        $this->em()->flush();

        $body = $this->preflight([
            'entity_type' => 'product',
            'target_scope' => 'all',
            'selected_columns' => ['sku', 'price.shopify'],
            'channels' => ['shopify'],
        ]);

        self::assertSame('sync', $body['mode']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function preflight(array $payload): array
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/exports/preflight', ['json' => $payload]);
        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function preflightRaw(array $payload): int
    {
        $client = $this->authenticatedClient();

        return $client->request('POST', '/api/exports/preflight', ['json' => $payload])->getStatusCode();
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function customObjectType(Tenant $tenant, string $code): ObjectType
    {
        $objectType = new ObjectType($code, ObjectKind::Custom, ['pl' => ucfirst($code), 'en' => ucfirst($code)]);
        $objectType->assignTenant($tenant);
        $this->em()->persist($objectType);

        return $objectType;
    }

    /**
     * @param array<string, mixed>|null $indexed
     */
    private function object(
        Tenant $tenant,
        ObjectType $objectType,
        string $code,
        ?array $indexed = null,
        ?string $status = null,
    ): void {
        $object = new CatalogObject($objectType, $code);
        $object->assignTenant($tenant);
        if (null !== $indexed) {
            $object->updateAttributeIndex($indexed);
        }
        if (null !== $status) {
            $object->forceStatus($status);
        }
        $this->em()->persist($object);
    }
}
