<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Event\EntityChanged;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use ArrayObject;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P0-05 (#1948) — the EntityChanged core hook fires on catalog
 * writes with the right tenant/id/kind, and with no listener registered
 * it is a no-op (no side effects, nothing accumulates).
 */
final class EntityChangedEmitterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function persistAndUpdateEmitEntityChangedWithTenantAndKind(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->tenantContext()->set($tenant);
        $em = $this->em();

        $captured = $this->captureEvents();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $object = new CatalogObject($type, 'HOOK-1');
        $em->persist($object);
        $attribute = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($attribute);
        $value = new ObjectValue($object, $attribute, ['value' => 'before']);
        $em->persist($value);
        $em->flush();

        $created = array_values(array_filter(
            iterator_to_array($captured),
            static fn (EntityChanged $e): bool => EntityChanged::KIND_CREATED === $e->changeKind,
        ));
        self::assertCount(2, $created, 'CatalogObject + ObjectValue persists must emit exactly two created events');

        $byType = [];
        foreach ($created as $event) {
            $byType[$event->entityType] = $event;
        }
        self::assertArrayHasKey('catalog_object', $byType);
        self::assertArrayHasKey('object_value', $byType);
        self::assertTrue($object->getId()->equals($byType['catalog_object']->entityId));
        self::assertTrue($value->getId()->equals($byType['object_value']->entityId));
        self::assertTrue($tenant->getId()->equals($byType['object_value']->tenantId));
        self::assertSame('catalog.entity.changed', $byType['object_value']->eventName());

        // Update path: mutate the value -> exactly one `updated` event for it.
        $captured->exchangeArray([]);
        $value->updateValue(['value' => 'after']);
        $em->flush();

        $updated = array_values(array_filter(
            iterator_to_array($captured),
            static fn (EntityChanged $e): bool => EntityChanged::KIND_UPDATED === $e->changeKind
                && 'object_value' === $e->entityType,
        ));
        self::assertCount(1, $updated);
        self::assertTrue($value->getId()->equals($updated[0]->entityId));
    }

    #[Test]
    public function noListenerMeansNoSideEffects(): void
    {
        // The hook exists independently of consumers (ADR-0024 a): a write
        // without any EntityChanged listener must succeed exactly as before.
        $tenant = $this->createTenant('alpha');
        $this->tenantContext()->set($tenant);
        $em = $this->em();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $object = new CatalogObject($type, 'HOOK-2');
        $em->persist($object);
        $em->flush();

        self::assertNotNull($object->getId());
    }

    /**
     * @return ArrayObject<int, EntityChanged>
     */
    private function captureEvents(): ArrayObject
    {
        /** @var ArrayObject<int, EntityChanged> $captured */
        $captured = new ArrayObject();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(EntityChanged::class, static function (EntityChanged $event) use ($captured): void {
            $captured->append($event);
        });

        return $captured;
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

    private function createTenant(string $code): Tenant
    {
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }
}
