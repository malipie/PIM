<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Bulk\BulkSetAttributeHandler;
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
 * #2664 — the `set_attribute` bulk action must write the CANONICAL per-type
 * envelope into attributes_indexed, not the raw scalar. A bare `price = "1"`
 * left the typed detail-form input empty (list showed "1", detail was blank);
 * the cache must carry `{amount:1, currency:PLN}` like the single-edit path.
 */
final class BulkSetAttributeCanonicalHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function setPriceWritesCanonicalEnvelopeIntoTheCache(): void
    {
        [$em, $type] = $this->fixture();
        $price = new Attribute('price', ['en' => 'Price'], AttributeType::Price);
        $price->updateValidationRules(['currencies' => ['PLN', 'EUR']]);
        $em->persist($price);
        $em->flush();

        $object = new CatalogObject($type, 'PRICE-1');
        $object->updateAttributeIndex(['name' => ['value' => 'Widget']]);
        $em->persist($object);
        $em->flush();

        $session = new BulkSession('set_attribute', [$object->getId()->toRfc4122()], ['attr' => 'price', 'value' => 1], null);
        $em->persist($session);
        $em->flush();

        $result = $this->handler()->handle($session, 'price', 1);
        self::assertSame(1, $result['success']);

        $em->clear();
        $slot = $em->getConnection()->fetchOne(
            "SELECT attributes_indexed->'price' FROM objects WHERE code = 'PRICE-1'",
        );
        self::assertIsString($slot);
        $decoded = json_decode($slot, true);
        self::assertIsArray($decoded);
        // Canonical price envelope — not the bare "1" that broke the detail form.
        self::assertSame(1, $decoded['amount'] ?? null);
        self::assertSame('PLN', $decoded['currency'] ?? null, 'default currency from validation_rules.currencies[0]');
    }

    #[Test]
    public function setTextKeepsThePlainEnvelope(): void
    {
        [$em, $type] = $this->fixture();
        $em->persist(new Attribute('subtitle', ['en' => 'Subtitle'], AttributeType::Text));
        $em->flush();

        $object = new CatalogObject($type, 'TEXT-1');
        $em->persist($object);
        $em->flush();

        $session = new BulkSession('set_attribute', [$object->getId()->toRfc4122()], ['attr' => 'subtitle', 'value' => 'Hello'], null);
        $em->persist($session);
        $em->flush();

        $this->handler()->handle($session, 'subtitle', 'Hello');

        $em->clear();
        $slot = $em->getConnection()->fetchOne(
            "SELECT attributes_indexed->'subtitle' FROM objects WHERE code = 'TEXT-1'",
        );
        self::assertIsString($slot);
        self::assertSame(['value' => 'Hello'], json_decode($slot, true));
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
        $em->flush();

        return [$em, $type];
    }

    private function handler(): BulkSetAttributeHandler
    {
        return self::getContainer()->get(BulkSetAttributeHandler::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
