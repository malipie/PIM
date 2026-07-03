<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Contracts\ExportTriggerPort;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P3-06 (#1966) — export as an ACTION: the trigger port creates
 * an ExportSession with source=agent and dispatches the same
 * RunExportMessage as the admin path. With the test transports being
 * synchronous the whole export runs inline — the session must complete
 * without error, proving the reuse end-to-end.
 */
final class TriggerExportTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function triggerCreatesAnAgentSessionAndTheExportCompletes(): void
    {
        $em = $this->fixture();

        $sessionId = $this->port()->trigger(
            userId: Uuid::v7(),
            objectTypeCode: 'product',
            filterDsl: [],
            columns: ['sku', 'status'],
            format: 'csv',
        );

        $row = $em->getConnection()->fetchAssociative(
            'SELECT source, format, status, error_message FROM export_sessions WHERE id = :id',
            ['id' => $sessionId->toRfc4122()],
        );
        if (\is_array($row) && 'error' === ($row['status'] ?? null)) {
            self::fail('export failed: '.(\is_string($row['error_message'] ?? null) ? $row['error_message'] : 'unknown'));
        }
        self::assertIsArray($row);
        self::assertSame('agent', $row['source'], 'the session must carry agent provenance');
        self::assertSame('csv', $row['format']);
        self::assertNotSame('error', $row['status'], 'the inline (sync transport) export must not fail');
    }

    #[Test]
    public function unknownObjectTypeIsRejected(): void
    {
        $this->fixture();

        $this->expectException(InvalidArgumentException::class);
        $this->port()->trigger(Uuid::v7(), 'no_such_type', [], ['sku'], 'csv');
    }

    #[Test]
    public function xmlFormatIsRejectedAsFeedTerritory(): void
    {
        $this->fixture();

        $this->expectException(InvalidArgumentException::class);
        $this->port()->trigger(Uuid::v7(), 'product', [], ['sku'], 'xml');
    }

    private function fixture(): EntityManagerInterface
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        // The export runner resolves the BUILT-IN Product ObjectType.
        self::getContainer()->get(BuiltInObjectTypeSeeder::class)->seed($tenant);
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($type instanceof ObjectType);
        $em->persist(new Attribute('name', ['en' => 'Name'], AttributeType::Text));
        $em->persist(new CatalogObject($type, 'SKU-1'));
        $em->flush();

        return $em;
    }

    private function port(): ExportTriggerPort
    {
        return self::getContainer()->get(ExportTriggerPort::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
