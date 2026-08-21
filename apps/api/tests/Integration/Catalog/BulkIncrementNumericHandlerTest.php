<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Bulk\BulkIncrementNumericHandler;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * VIEW-13 (#545) regression — the `increment_numeric` bulk action reads
 * and writes the numeric through the attributes_indexed ENVELOPE
 * ({value: X}), not as a bare scalar. The handler previously read the
 * slot directly, so is_numeric() was false for every row and the whole
 * batch was silently skipped as "not numeric" — arithmetic did nothing.
 */
final class BulkIncrementNumericHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function multiplyReadsAndWritesThroughTheEnvelope(): void
    {
        [$em, $type] = $this->fixture();

        $priced = new CatalogObject($type, 'PRICED-1');
        $priced->updateAttributeIndex([
            'name' => ['value' => 'Z ceną'],
            'price' => ['value' => 100, 'provenance' => 'manual'],
        ]);
        $em->persist($priced);

        $noPrice = new CatalogObject($type, 'NOPRICE-1');
        $noPrice->updateAttributeIndex(['name' => ['value' => 'Bez ceny']]);
        $em->persist($noPrice);
        $em->flush();

        $session = new BulkSession(
            'increment_numeric',
            [$priced->getId()->toRfc4122(), $noPrice->getId()->toRfc4122()],
            ['attr' => 'price', 'operator' => '*', 'operand' => 2],
            null,
        );
        $em->persist($session);
        $em->flush();

        $result = $this->handler()->handle($session, 'price', '*', 2.0);

        self::assertSame(1, $result['success'], 'the priced object must be multiplied');
        self::assertSame(1, $result['skipped'], 'the object with no price is skipped, not errored');
        self::assertSame(0, $result['error']);

        $em->clear();
        $conn = $em->getConnection();

        // The scalar doubled...
        $value = $conn->fetchOne(
            "SELECT (attributes_indexed->'price'->>'value')::numeric FROM objects WHERE code = :c",
            ['c' => 'PRICED-1'],
        );
        self::assertEqualsWithDelta(200.0, (float) (\is_scalar($value) ? $value : -1), 0.0001);

        // ...and the envelope + provenance survived (not flattened to a scalar).
        $provenance = $conn->fetchOne(
            "SELECT attributes_indexed->'price'->>'provenance' FROM objects WHERE code = :c",
            ['c' => 'PRICED-1'],
        );
        self::assertSame('manual', $provenance, 'the arithmetic must preserve the slot envelope');
    }

    #[Test]
    public function priceEnvelopeIsIncrementedKeepingCurrencyAndScale(): void
    {
        // #2946 — a price is {amount, currency}, so the number is under
        // `amount`. Reading only `value` made every price row "not numeric":
        // the action ran, reported success, and changed nothing.
        [$em, $type] = $this->fixture();

        $priced = new CatalogObject($type, 'PRICE-ENV-1');
        $priced->updateAttributeIndex([
            'price' => ['amount' => 299.00, 'currency' => 'PLN', 'provenance' => 'manual'],
        ]);
        $em->persist($priced);
        $em->flush();

        $session = new BulkSession(
            'increment_numeric',
            [$priced->getId()->toRfc4122()],
            ['attr' => 'price', 'operator' => '*', 'operand' => 1.1],
            null,
        );
        $em->persist($session);
        $em->flush();

        $result = $this->handler()->handle($session, 'price', '*', 1.1);

        self::assertSame(1, $result['success'], 'a price row must no longer be skipped');
        self::assertSame(0, $result['skipped']);

        $em->clear();
        $conn = $em->getConnection();

        // 10% on 299.00 is 328.90 — not 328.90000000000003.
        $amount = $conn->fetchOne(
            "SELECT attributes_indexed->'price'->>'amount' FROM objects WHERE code = :c",
            ['c' => 'PRICE-ENV-1'],
        );
        self::assertSame('328.9', (string) (\is_scalar($amount) ? $amount : ''));

        // Currency and provenance ride along untouched.
        $currency = $conn->fetchOne(
            "SELECT attributes_indexed->'price'->>'currency' FROM objects WHERE code = :c",
            ['c' => 'PRICE-ENV-1'],
        );
        self::assertSame('PLN', $currency);
        $provenance = $conn->fetchOne(
            "SELECT attributes_indexed->'price'->>'provenance' FROM objects WHERE code = :c",
            ['c' => 'PRICE-ENV-1'],
        );
        self::assertSame('manual', $provenance);

        // The scalar `value` key is NOT invented on a price envelope.
        $value = $conn->fetchOne(
            "SELECT attributes_indexed->'price'->>'value' FROM objects WHERE code = :c",
            ['c' => 'PRICE-ENV-1'],
        );
        self::assertNull($value === false ? null : $value);
    }

    /**
     * @return array{0: EntityManagerInterface, 1: ObjectType}
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
        $em->persist(new Attribute('price', ['en' => 'Price'], AttributeType::Number));
        $em->flush();

        return [$em, $type];
    }

    private function handler(): BulkIncrementNumericHandler
    {
        return self::getContainer()->get(BulkIncrementNumericHandler::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
