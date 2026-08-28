<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * IMP2-1.2 (#1464, ADR-0019) — the data-migration {@see Version20260612210000}
 * normalises legacy `{value: ...}` JSONB envelopes into the per-AttributeType
 * canon, on BOTH `object_values.value` and the denormalised
 * `objects.attributes_indexed` cache.
 *
 * The test DB schema is built from ORM metadata (Foundry reset mode: schema),
 * NOT from `migrations:migrate`, so the migration's structural `up(Schema)`
 * never runs here. Instead the migration's planned data SQL is extracted via
 * {@see AbstractMigration::getSql()} and executed verbatim on the connection —
 * the same statements that ship to production, exercised against seeded legacy
 * rows.
 *
 * Two contracts are asserted:
 *  - canonicalisation: each legacy shape lands on its canonical key
 *    (select→option_code, multiselect→option_codes incl. the bare-array case,
 *    price→amount), preserving sibling keys (locale, channel meta);
 *  - idempotency: a second pass of the same SQL changes nothing (the WHERE
 *    guards exclude already-canonical rows).
 */
final class CanonMigrationTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;
    private ObjectType $productType;

    /** @var array<string, Attribute> code => attribute */
    private array $attributes = [];

    private CatalogObject $product;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = $this->em();
        $this->tenant = new Tenant('demo', 'Demo');
        $em->persist($this->tenant);
        $em->flush();
        $this->tenantContext()->set($this->tenant);

        $this->productType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($this->productType);

        foreach ([
            'sel' => AttributeType::Select,
            'multi' => AttributeType::Multiselect,
            'multi_bare' => AttributeType::Multiselect,
            'prc' => AttributeType::Price,
            'txt' => AttributeType::Text,
        ] as $code => $type) {
            $attribute = new Attribute($code, ['en' => $code], $type);
            $em->persist($attribute);
            $this->attributes[$code] = $attribute;
        }

        $this->product = new CatalogObject($this->productType, 'CANON-1');
        $em->persist($this->product);
        $em->flush();
    }

    #[Test]
    public function migrationNormalisesLegacyObjectValueEnvelopes(): void
    {
        $em = $this->em();

        // ── Seed legacy `{value: ...}` envelopes via entities (verbatim store). ──
        // select: {value:"red"} → {option_code:"red"}; the locale sibling must
        // survive (the migration does `jsonb_build_object(...) || (value - 'value')`).
        $sel = new ObjectValue($this->product, $this->attributes['sel'], ['value' => 'red', 'extra' => 'keep']);
        // multiselect object form: {value:[...]} → {option_codes:[...]}.
        $multi = new ObjectValue($this->product, $this->attributes['multi'], ['value' => ['new', 'sale']]);
        // multiselect BARE array: [...] → {option_codes:[...]}. A JSON list is
        // not expressible through the array<string, mixed> constructor, so the
        // row is seeded with a placeholder and its `value` raw-set to the bare
        // array below — the exact legacy shape the migration must handle.
        $multiBare = new ObjectValue($this->product, $this->attributes['multi_bare'], ['value' => 'placeholder']);
        // price object form: {value:249.99} → {amount:249.99}.
        $price = new ObjectValue($this->product, $this->attributes['prc'], ['value' => 249.99]);
        // text: already canonical, untouched (negative control).
        $text = new ObjectValue($this->product, $this->attributes['txt'], ['value' => 'leave me']);

        foreach ([$sel, $multi, $multiBare, $price, $text] as $value) {
            $em->persist($value);
        }
        $em->flush();
        $ids = [
            'sel' => $sel->getId()->toRfc4122(),
            'multi' => $multi->getId()->toRfc4122(),
            'multi_bare' => $multiBare->getId()->toRfc4122(),
            'prc' => $price->getId()->toRfc4122(),
            'txt' => $text->getId()->toRfc4122(),
        ];

        // Overwrite the multi_bare row with the bare JSON array legacy shape.
        $em->getConnection()->executeStatement(
            "UPDATE object_values SET value = '[\"new\", \"sale\"]'::jsonb WHERE id = :id",
            ['id' => $ids['multi_bare']],
        );
        $em->clear();

        // ── Act: run the migration's data SQL directly on the connection. ──
        // The plain object_values UPDATEs report affected rows (the
        // attributes_indexed DO block does not — covered in the cache test).
        $changed = $this->runMigrationSql();
        self::assertGreaterThan(0, $changed, 'first pass must rewrite legacy rows');

        // ── Assert canon for object_values (jsonb does not preserve key order,
        // so compare canonicalised). ──
        self::assertEqualsCanonicalizing(
            ['option_code' => 'red', 'extra' => 'keep'],
            $this->valueOf($ids['sel']),
            'select {value} → {option_code} keeps siblings',
        );
        self::assertSame(['option_codes' => ['new', 'sale']], $this->valueOf($ids['multi']), 'multiselect object form');
        self::assertSame(['option_codes' => ['new', 'sale']], $this->valueOf($ids['multi_bare']), 'multiselect bare array');
        self::assertSame(['amount' => 249.99], $this->valueOf($ids['prc']), 'price {value} → {amount}');
        self::assertSame(['value' => 'leave me'], $this->valueOf($ids['txt']), 'text envelope untouched');

        // ── Idempotency: a second pass of the same SQL is a no-op. ──
        $canonAfterFirst = array_map(fn (string $id): array => $this->valueOf($id), $ids);
        $changedSecond = $this->runMigrationSql();
        self::assertSame(0, $changedSecond, 'second pass rewrites nothing (WHERE guards exclude canonical rows)');
        foreach ($ids as $code => $id) {
            self::assertSame($canonAfterFirst[$code], $this->valueOf($id), "value for {$code} stable after re-run");
        }
    }

    #[Test]
    public function migrationNormalisesLegacyAttributesIndexedCache(): void
    {
        $em = $this->em();

        // The sync listener rewrites attributes_indexed on persist, so the legacy
        // denormalised shape is injected via a raw UPDATE that bypasses the ORM.
        $legacyIndexed = [
            'sel' => ['value' => 'red', 'extra' => 'keep'],
            'multi' => ['value' => ['new', 'sale']],
            'multi_bare' => ['new', 'sale'],
            'prc' => ['value' => 249.99],
            'txt' => ['value' => 'leave me'],
        ];
        $em->getConnection()->executeStatement(
            'UPDATE objects SET attributes_indexed = :indexed::jsonb WHERE id = :id',
            [
                'indexed' => json_encode($legacyIndexed, JSON_THROW_ON_ERROR),
                'id' => $this->product->getId()->toRfc4122(),
            ],
        );
        $em->clear();
        $legacyState = $this->attributesIndexed();

        // ── Act: run the migration's data SQL (covers the attributes_indexed DO
        // block). The PL/pgSQL DO statement does not report inner affected rows,
        // so the rewrite is proven by the state change below, not a row count. ──
        $this->runMigrationSql();

        // ── Assert canon for attributes_indexed. ──
        $indexed = $this->attributesIndexed();
        self::assertNotSame($legacyState, $indexed, 'first pass must rewrite the legacy cache');
        self::assertEqualsCanonicalizing(['option_code' => 'red', 'extra' => 'keep'], $indexed['sel'], 'select cache canonicalised');
        self::assertSame(['option_codes' => ['new', 'sale']], $indexed['multi'], 'multiselect object cache');
        self::assertSame(['option_codes' => ['new', 'sale']], $indexed['multi_bare'], 'multiselect bare-array cache');
        self::assertSame(['amount' => 249.99], $indexed['prc'], 'price cache canonicalised');
        self::assertSame(['value' => 'leave me'], $indexed['txt'], 'text cache untouched');

        // ── Idempotency: a second pass leaves the canonical cache untouched. ──
        $this->runMigrationSql();
        self::assertSame($indexed, $this->attributesIndexed(), 'cache stable after re-run');
    }

    /**
     * Extract and execute the migration's planned data SQL on the connection.
     * The schema-built test DB never runs `migrations:migrate`, so this replays
     * the exact statements `up()` ships — and returns the total affected-row
     * count so callers can assert idempotency.
     */
    private function runMigrationSql(): int
    {
        // Migration classes are intentionally NOT autoloaded (see
        // config/packages/doctrine_migrations.yaml). require_once defines the
        // class; the MigrationFactory then wires the connection + logger and
        // returns a typed AbstractMigration (the same path the migrator uses).
        require_once \dirname(__DIR__, 3).'/migrations/Version20260612210000.php';
        $migration = self::getContainer()
            ->get('doctrine.migrations.dependency_factory')
            ->getMigrationFactory()
            ->createVersion('DoctrineMigrations\\Version20260612210000');
        $migration->up(new Schema());

        $connection = $this->em()->getConnection();
        $affected = 0;
        foreach ($migration->getSql() as $query) {
            // The migration binds no parameters — every statement is literal
            // SQL — so the statement string alone reproduces it faithfully.
            $affected += (int) $connection->executeStatement($query->getStatement());
        }
        $this->em()->clear();

        return $affected;
    }

    /**
     * @return array<string, mixed>
     */
    private function valueOf(string $objectValueId): array
    {
        $raw = $this->em()->getConnection()->fetchOne(
            'SELECT value FROM object_values WHERE id = :id',
            ['id' => $objectValueId],
        );
        \assert(\is_string($raw));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesIndexed(): array
    {
        $raw = $this->em()->getConnection()->fetchOne(
            'SELECT attributes_indexed FROM objects WHERE id = :id',
            ['id' => $this->product->getId()->toRfc4122()],
        );
        \assert(\is_string($raw));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
