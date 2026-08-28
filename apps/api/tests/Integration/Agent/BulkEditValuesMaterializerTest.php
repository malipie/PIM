<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Application\PendingChanges\AgentValueNormalizer;
use App\Catalog\Application\PendingChanges\BulkEditValuesMaterializer;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Command\BulkEditValuesPort;
use App\Catalog\Contracts\Command\PendingBatchCommitPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P3-01 (#1961, SEC failing-test-first) — materialization writes
 * ONLY pending diffs: the catalog stays untouched (object_values = 0,
 * attributes_indexed unchanged), only_empty skips filled values,
 * validation rejects with the manual-edit validators, and per-attribute
 * RBAC rejects codes outside the user's edit scope.
 */
final class BulkEditValuesMaterializerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function materializesDiffsWithoutTouchingTheCatalog(): void
    {
        [$tenant, $em, $type] = $this->fixture();

        $noPrice = new CatalogObject($type, 'NOPRICE-1');
        $noPrice->updateAttributeIndex(['name' => ['value' => 'Bez ceny']]);
        $em->persist($noPrice);

        $withPrice = new CatalogObject($type, 'PRICED-1');
        $withPrice->updateAttributeIndex(['name' => ['value' => 'Z ceną'], 'price' => ['amount' => 250, 'currency' => 'EUR']]);
        $em->persist($withPrice);
        $em->flush();

        $batchId = Uuid::v7();
        $proposal = $this->port()->materializeValueEdits(
            batchId: $batchId,
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            changes: ['price' => 100],
            mode: 'only_empty',
        );

        self::assertSame(1, $proposal->affectedObjects);
        self::assertSame(1, $proposal->materializedChanges);
        self::assertSame(1, $proposal->skippedExisting);
        self::assertSame([], $proposal->rejected);
        self::assertSame('only_empty', $proposal->mode);
        self::assertCount(1, $proposal->skippedExistingExamples);
        self::assertSame('PRICED-1', $proposal->skippedExistingExamples[0]['object_code']);
        self::assertSame(['amount' => 250, 'currency' => 'EUR'], $proposal->skippedExistingExamples[0]['current_value']);

        // SEC: ZERO catalog writes before approval.
        $conn = $em->getConnection();
        $valueRows = $conn->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(0, (int) (\is_scalar($valueRows) ? $valueRows : -1), 'no ObjectValue may exist before approval');
        $indexed = $conn->fetchOne('SELECT attributes_indexed FROM objects WHERE code = :c', ['c' => 'NOPRICE-1']);
        self::assertIsString($indexed);
        self::assertStringNotContainsString('100', $indexed, 'attributes_indexed must be untouched');

        // The batch holds the diff with the canonical envelope + provenance=agent.
        $rows = $this->pendingChanges()->listBatch($batchId);
        self::assertCount(1, $rows);
        self::assertSame('agent', $rows[0]->provenance);
        self::assertSame(PendingChangeStatus::Pending, $rows[0]->status);
        self::assertSame(['mode' => 'only_empty'], $rows[0]->meta);
        self::assertSame('price', $rows[0]->attributeCode);
        self::assertNull($rows[0]->before);
        self::assertSame(['amount' => 100, 'currency' => 'EUR'], $rows[0]->after);
        self::assertTrue($noPrice->getId()->equals($rows[0]->targetObjectId ?? Uuid::v4()));
    }

    #[Test]
    public function overwriteModeCapturesBeforeState(): void
    {
        [, $em, $type] = $this->fixture();

        $withPrice = new CatalogObject($type, 'PRICED-2');
        $withPrice->updateAttributeIndex(['price' => ['amount' => 250, 'currency' => 'EUR']]);
        $em->persist($withPrice);
        $em->flush();

        $batchId = Uuid::v7();
        $proposal = $this->port()->materializeValueEdits(
            batchId: $batchId,
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            changes: ['price' => 100],
            mode: 'overwrite',
        );

        self::assertSame(1, $proposal->materializedChanges);
        $rows = $this->pendingChanges()->listBatch($batchId);
        self::assertSame(['amount' => 250, 'currency' => 'EUR'], $rows[0]->before, 'overwrite must capture the before envelope for the diff');
        self::assertSame(['amount' => 100, 'currency' => 'EUR'], $rows[0]->after);
    }

    #[Test]
    public function invalidValueIsRejectedByTheManualEditValidators(): void
    {
        [, $em, $type] = $this->fixture();
        $em->persist(new CatalogObject($type, 'ANY-1'));
        $em->flush();

        $proposal = $this->port()->materializeValueEdits(
            batchId: Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            changes: ['price' => 'not-a-number'],
            mode: 'overwrite',
        );

        self::assertSame(0, $proposal->materializedChanges);
        self::assertCount(1, $proposal->rejected);
        self::assertSame('price', $proposal->rejected[0]['code']);
    }

    #[Test]
    public function unknownAttributeIsRejectedNotMaterialized(): void
    {
        [, $em, $type] = $this->fixture();
        $em->persist(new CatalogObject($type, 'ANY-2'));
        $em->flush();

        $proposal = $this->port()->materializeValueEdits(
            batchId: Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            changes: ['no_such_attr' => 'x'],
            mode: 'overwrite',
        );

        self::assertSame(0, $proposal->materializedChanges);
        self::assertSame('Unknown attribute.', $proposal->rejected[0]['reason']);
    }

    #[Test]
    public function attributeOutsideEditScopeIsRejectedByRbac(): void
    {
        [, $em, $type] = $this->fixture();
        $em->persist(new CatalogObject($type, 'ANY-3'));
        $em->flush();

        $proposal = $this->port(rbacAllows: false)->materializeValueEdits(
            batchId: Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            changes: ['price' => 100],
            mode: 'overwrite',
        );

        self::assertSame(0, $proposal->materializedChanges, 'nothing may be materialized outside the user scope');
        self::assertSame('Attribute is outside your edit permissions.', $proposal->rejected[0]['reason']);
        self::assertSame(1, $proposal->permissionRejectedAttributes);
    }

    #[Test]
    public function worksOnOtherObjectTypesThanProduct(): void
    {
        // AGENT-P8-05 (#1987) — the tools are ObjectType-agnostic: the
        // same materializer handles a CATEGORY-kind type (and any custom
        // kind) because the engines are parameterized by type, not
        // hard-coded to product.
        [$tenant, $em] = $this->fixture();
        $categoryType = new ObjectType('landing_category', ObjectKind::Category, ['en' => 'Landing category']);
        $em->persist($categoryType);
        $em->persist(new CatalogObject($categoryType, 'CAT-LANDING'));
        $em->flush();

        $proposal = $this->port()->materializeValueEdits(
            batchId: $batchId = Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'landing_category',
            filterDsl: [],
            changes: ['name' => 'Landing DE'],
            mode: 'overwrite',
        );

        self::assertSame(1, $proposal->affectedObjects);
        $rows = $this->pendingChanges()->listBatch($batchId);
        self::assertCount(1, $rows);
        self::assertSame('name', $rows[0]->attributeCode);
    }

    #[Test]
    public function arithmeticMultiplyMaterializesComputedDiffPerObject(): void
    {
        // The agent counterpart of the manual increment_numeric bulk
        // action: "double the price" -> operator '*', operand 2. The
        // after-value is computed per object from its current envelope
        // value, still through the approval path (nothing committed).
        [, $em, $type] = $this->fixture();

        $cheap = new CatalogObject($type, 'CHEAP-1');
        $cheap->updateAttributeIndex(['price' => ['amount' => 100, 'currency' => 'EUR', 'provenance' => 'manual']]);
        $em->persist($cheap);

        $pricey = new CatalogObject($type, 'PRICEY-1');
        $pricey->updateAttributeIndex(['price' => ['amount' => 250, 'currency' => 'EUR']]);
        $em->persist($pricey);

        $noPrice = new CatalogObject($type, 'NOPRICE-A');
        $noPrice->updateAttributeIndex(['name' => ['value' => 'Bez ceny']]);
        $em->persist($noPrice);
        $em->flush();

        $batchId = Uuid::v7();
        $proposal = $this->port()->materializeArithmeticEdits(
            batchId: $batchId,
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            attrCode: 'price',
            operator: '*',
            operand: 2.0,
        );

        self::assertSame(2, $proposal->affectedObjects, 'both priced objects computed');
        self::assertSame(2, $proposal->materializedChanges);
        self::assertSame(1, $proposal->skippedExisting, 'the object with no price is skipped, not errored');
        self::assertSame([], $proposal->rejected);

        // SEC: nothing committed — the catalog stays at the old values.
        $conn = $em->getConnection();
        $live = $conn->fetchOne(
            "SELECT (attributes_indexed->'price'->>'amount')::numeric FROM objects WHERE code = :c",
            ['c' => 'CHEAP-1'],
        );
        self::assertEqualsWithDelta(100.0, (float) (\is_scalar($live) ? $live : -1), 0.0001);

        // The batch carries the computed before->after diff with provenance=agent.
        $rows = $this->pendingChanges()->listBatch($batchId);
        $byObject = [];
        foreach ($rows as $row) {
            self::assertSame('agent', $row->provenance);
            self::assertSame('price', $row->attributeCode);
            $byObject[$row->targetObjectId?->toRfc4122() ?? ''] = $row;
        }
        $cheapRow = $byObject[$cheap->getId()->toRfc4122()] ?? null;
        self::assertNotNull($cheapRow);
        self::assertSame(['amount' => 100, 'currency' => 'EUR', 'provenance' => 'manual'], $cheapRow->before, 'before keeps the full envelope');
        self::assertSame(['amount' => 200.0, 'currency' => 'EUR'], $cheapRow->after, '100 * 2 = 200 and keeps currency');
    }

    #[Test]
    public function arithmeticDivideByZeroSkipsInsteadOfErroring(): void
    {
        [, $em, $type] = $this->fixture();
        $obj = new CatalogObject($type, 'DIV-1');
        $obj->updateAttributeIndex(['price' => ['amount' => 100, 'currency' => 'EUR']]);
        $em->persist($obj);
        $em->flush();

        $proposal = $this->port()->materializeArithmeticEdits(
            batchId: Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            attrCode: 'price',
            operator: '/',
            operand: 0.0,
        );

        self::assertSame(0, $proposal->materializedChanges);
        self::assertSame(1, $proposal->skippedExisting, 'division by zero is skipped, never errored');
        self::assertSame([], $proposal->rejected);
    }

    #[Test]
    public function arithmeticRejectsAttributeOutsideEditScope(): void
    {
        [, $em, $type] = $this->fixture();
        $obj = new CatalogObject($type, 'RBAC-A');
        $obj->updateAttributeIndex(['price' => ['amount' => 100, 'currency' => 'EUR']]);
        $em->persist($obj);
        $em->flush();

        $proposal = $this->port(rbacAllows: false)->materializeArithmeticEdits(
            batchId: Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            attrCode: 'price',
            operator: '*',
            operand: 2.0,
        );

        self::assertSame(0, $proposal->materializedChanges, 'nothing may be materialized outside the user scope');
        self::assertSame('Attribute is outside your edit permissions.', $proposal->rejected[0]['reason']);
    }

    #[Test]
    public function selectionScopeTargetsOnlyTheSelectedObjectsAndIgnoresForeignIds(): void
    {
        // #2153 — when the operator has rows selected, the write targets
        // exactly that selection (validated against tenant + type: a bogus
        // id simply matches no row, it cannot widen the scope).
        [, $em, $type] = $this->fixture();

        $a = new CatalogObject($type, 'SEL-A');
        $b = new CatalogObject($type, 'SEL-B');
        $c = new CatalogObject($type, 'SEL-C');
        foreach ([$a, $b, $c] as $obj) {
            $em->persist($obj);
        }
        $em->flush();

        $batchId = Uuid::v7();
        $proposal = $this->port()->materializeValueEdits(
            batchId: $batchId,
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            changes: ['price' => 100],
            mode: 'overwrite',
            selectedIds: [$a->getId()->toRfc4122(), $c->getId()->toRfc4122(), Uuid::v7()->toRfc4122()],
        );

        self::assertSame(2, $proposal->affectedObjects, 'only the two real selected objects (foreign id ignored)');
        self::assertSame(2, $proposal->materializedChanges);
        self::assertSame(2, $proposal->selectorMatchedObjects);
        self::assertSame(1, $proposal->selectorRejected);

        $rows = $this->pendingChanges()->listBatch($batchId);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->targetObjectId?->toRfc4122();
        }
        sort($ids);
        $expected = [$a->getId()->toRfc4122(), $c->getId()->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $ids, 'SEL-B (not selected) must be untouched');
    }

    #[Test]
    public function arithmeticSelectionScopeTargetsOnlyTheSelectedObjects(): void
    {
        [, $em, $type] = $this->fixture();

        $a = new CatalogObject($type, 'ARSEL-A');
        $a->updateAttributeIndex(['price' => ['amount' => 100, 'currency' => 'EUR']]);
        $b = new CatalogObject($type, 'ARSEL-B');
        $b->updateAttributeIndex(['price' => ['amount' => 200, 'currency' => 'EUR']]);
        $em->persist($a);
        $em->persist($b);
        $em->flush();

        $proposal = $this->port()->materializeArithmeticEdits(
            batchId: Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            attrCode: 'price',
            operator: '*',
            operand: 2.0,
            selectedIds: [$a->getId()->toRfc4122()],
        );

        self::assertSame(1, $proposal->affectedObjects, 'only the selected object is adjusted');
        self::assertSame(1, $proposal->materializedChanges);
    }

    #[Test]
    public function typedValuesUseCanonicalEnvelopesAndCommitUnchangedAfterApproval(): void
    {
        [, $em, $type] = $this->fixture();
        $object = new CatalogObject($type, 'TYPED-1');
        $em->persist($object);
        $em->flush();

        $assetId = Uuid::v7()->toRfc4122();
        $relatedId = Uuid::v7()->toRfc4122();
        $expected = [
            'gender' => ['option_code' => 'female'],
            'audiences' => ['option_codes' => ['adult', 'children']],
            'price' => ['amount' => 200, 'currency' => 'EUR'],
            'width' => ['value' => 300, 'unit' => 'mm'],
            'hero_asset' => ['asset_id' => $assetId],
            'related_product' => ['object_id' => $relatedId],
        ];

        $batchId = Uuid::v7();
        $proposal = $this->port()->materializeValueEdits(
            batchId: $batchId,
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            changes: [
                // #2987 — the exact production report used the stable option
                // code `female`; codes and unambiguous labels are both valid.
                'gender' => 'female',
                'audiences' => ['Dorośli', 'children'],
                'price' => 200,
                'width' => ['value' => 300, 'unit' => 'mm'],
                'hero_asset' => $assetId,
                'related_product' => $relatedId,
            ],
            mode: 'overwrite',
        );

        self::assertSame(6, $proposal->materializedChanges);
        self::assertSame([], $proposal->rejected);

        $materialized = [];
        foreach ($this->pendingChanges()->listBatch($batchId) as $change) {
            self::assertNotNull($change->attributeCode);
            $materialized[$change->attributeCode] = $change->after;
        }
        self::assertEquals($expected, $materialized, 'agent proposals must use the same per-type canon as the shared write core');

        $result = $this->committer()->commitAcceptedBatch($batchId, Uuid::v7());
        self::assertSame(6, $result->committedValues);

        $stored = [];
        foreach ($em->getConnection()->fetchAllAssociative(
            'SELECT a.code, ov.value::text AS value FROM object_values ov JOIN attributes a ON a.id = ov.attribute_id JOIN objects co ON co.id = ov.object_id WHERE co.code = :code',
            ['code' => 'TYPED-1'],
        ) as $row) {
            if (!\is_string($row['code'] ?? null) || !\is_string($row['value'] ?? null)) {
                continue;
            }
            $decoded = json_decode($row['value'], true);
            self::assertIsArray($decoded);
            $stored[$row['code']] = $decoded;
        }
        $expectedStored = $expected;
        ksort($expectedStored);
        ksort($stored);
        self::assertEquals($expectedStored, $stored, 'approval must persist the canonical proposal envelopes without shape drift');
    }

    #[Test]
    public function unknownOptionLabelIsRejectedWithAllowedCodes(): void
    {
        [, $em, $type] = $this->fixture();
        $em->persist(new CatalogObject($type, 'OPTION-1'));
        $em->flush();

        $proposal = $this->port()->materializeValueEdits(
            batchId: Uuid::v7(),
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            changes: ['gender' => 'Nieistniejąca'],
            mode: 'overwrite',
        );

        self::assertSame(0, $proposal->materializedChanges);
        self::assertSame('gender', $proposal->rejected[0]['code']);
        self::assertStringContainsString('attribute "gender"', $proposal->rejected[0]['reason']);
        self::assertStringContainsString('Available option codes: unisex, female.', $proposal->rejected[0]['reason']);
    }

    /**
     * @return array{0: Tenant, 1: EntityManagerInterface, 2: ObjectType}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $em->persist(new Attribute('name', ['en' => 'Name'], AttributeType::Text));

        $price = new Attribute('price', ['en' => 'Price'], AttributeType::Price);
        $price->updateValidationRules(['currencies' => ['EUR', 'PLN']]);
        $em->persist($price);

        $gender = new Attribute('gender', ['en' => 'Gender'], AttributeType::Select);
        $em->persist($gender);
        $em->persist(new AttributeOption($gender, 'unisex', ['pl' => 'Unisex', 'en' => 'Unisex']));
        $em->persist(new AttributeOption($gender, 'female', ['pl' => 'Damskie', 'en' => 'Female'], 1));

        $audiences = new Attribute('audiences', ['en' => 'Audiences'], AttributeType::Multiselect);
        $em->persist($audiences);
        $em->persist(new AttributeOption($audiences, 'adult', ['pl' => 'Dorośli', 'en' => 'Adults']));
        $em->persist(new AttributeOption($audiences, 'children', ['pl' => 'Dzieci', 'en' => 'Children'], 1));

        $width = new Attribute('width', ['en' => 'Width'], AttributeType::Metric);
        $width->updateValidationRules(['units' => ['mm', 'cm']]);
        $em->persist($width);
        $em->persist(new Attribute('hero_asset', ['en' => 'Hero asset'], AttributeType::Asset));
        $em->persist(new Attribute('related_product', ['en' => 'Related product'], AttributeType::Relation));
        $em->flush();

        return [$tenant, $em, $type];
    }

    private function port(bool $rbacAllows = true): BulkEditValuesPort
    {
        // Constructed with a scripted RBAC checker: the container's real
        // by-user-id checker fails closed for the random test user ids
        // (correct in prod, wrong for exercising the other guards here).
        return new BulkEditValuesMaterializer(
            $this->em(),
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(FilterDslResolver::class),
            self::getContainer()->get(AgentValueNormalizer::class),
            $this->rbac($rbacAllows),
            $this->pendingChanges(),
        );
    }

    private function rbac(bool $allows): UserScopedPermissionCheckerInterface
    {
        return new class($allows) implements UserScopedPermissionCheckerInterface {
            public function __construct(private readonly bool $allows)
            {
            }

            public function canViewAttribute(Uuid $userId, Uuid $attributeId): bool
            {
                return $this->allows;
            }

            public function canEditAttribute(Uuid $userId, Uuid $attributeId): bool
            {
                return $this->allows;
            }

            public function canEditLocale(Uuid $userId, string $locale): bool
            {
                return $this->allows;
            }

            public function canEditChannel(Uuid $userId, string $channel): bool
            {
                return $this->allows;
            }
        };
    }

    private function pendingChanges(): PendingChangesPort
    {
        return self::getContainer()->get(PendingChangesPort::class);
    }

    private function committer(): PendingBatchCommitPort
    {
        return self::getContainer()->get(PendingBatchCommitPort::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
