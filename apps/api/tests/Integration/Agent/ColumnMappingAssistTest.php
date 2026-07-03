<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\SuggestColumnMappingTool;
use App\Agent\Application\Tool\ToolKind;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Import\Contracts\ColumnMappingPort;
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
 * AGENT-P8-02 (#1984) — AI-assisted mapping keeps the deterministic
 * AutoMapper as the DEFAULT: an exact header match comes back as
 * confidence=auto with the code, an unknown header as manual with the
 * attribute catalogue alongside - the Opus model fills only those gaps
 * and nothing is applied by the tool.
 */
final class ColumnMappingAssistTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function deterministicBaselinePlusCatalogueForTheModel(): void
    {
        $this->fixture();

        $tool = new SuggestColumnMappingTool(self::getContainer()->get(ColumnMappingPort::class));
        self::assertSame(ToolKind::Schema, $tool->kind());
        self::assertSame('imports.run', $tool->requiredPermission());

        $result = $tool->execute([
            'headers' => ['price', 'Tajemnicza Kolumna X17'],
            'sample_rows' => [
                ['price' => '19.99', 'Tajemnicza Kolumna X17' => 'bawełna 80%'],
            ],
        ], $this->context());

        self::assertIsArray($result['suggestions']);
        self::assertCount(2, $result['suggestions']);

        $byHeader = [];
        foreach ($result['suggestions'] as $suggestion) {
            self::assertIsArray($suggestion);
            self::assertIsString($suggestion['header']);
            $byHeader[$suggestion['header']] = $suggestion;
        }

        self::assertSame('price', $byHeader['price']['suggested_code'], 'the deterministic mapper stays the default');
        self::assertSame('auto', $byHeader['price']['confidence']);
        self::assertNull($byHeader['Tajemnicza Kolumna X17']['suggested_code']);
        self::assertSame('manual', $byHeader['Tajemnicza Kolumna X17']['confidence'], 'ambiguous columns are the model\'s territory');

        self::assertIsArray($result['attribute_catalogue']);
        $codes = array_column($result['attribute_catalogue'], 'code');
        self::assertContains('price', $codes);
        self::assertContains('material', $codes, 'the catalogue grounds the model\'s proposal');
    }

    #[Test]
    public function emptyHeadersAreAnError(): void
    {
        $this->fixture();

        $tool = new SuggestColumnMappingTool(self::getContainer()->get(ColumnMappingPort::class));
        $result = $tool->execute(['headers' => []], $this->context());

        self::assertArrayHasKey('error', $result);
    }

    private function fixture(): void
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $em->persist(new Attribute('price', ['en' => 'Price', 'pl' => 'Cena'], AttributeType::Number));
        $em->persist(new Attribute('material', ['en' => 'Material', 'pl' => 'Materiał'], AttributeType::Text));
        $em->flush();
    }

    private function context(): AgentToolContext
    {
        $tenant = self::getContainer()->get(TenantContext::class)->get();
        \assert($tenant instanceof Tenant);

        return new AgentToolContext(Uuid::v7(), $tenant, []);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
