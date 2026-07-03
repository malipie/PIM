<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\GenerateFeedTool;
use App\Agent\Application\Tool\SuggestFeedStructureTool;
use App\Agent\Application\Tool\ToolKind;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Export\Contracts\FeedAssistPort;
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
 * AGENT-P7-01 (#1981) — engine-gated tools light up now that XMLF is
 * on main: suggest_feed_structure evaluates a built-in template on a
 * REAL data sample through the engine's preview (slots + XML + health
 * report), generate_feed lists feeds when none picked, and an unknown
 * template is refused. Zero loop changes - registration only.
 */
final class FeedToolsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function suggestStructureEvaluatesTheTemplateOnASample(): void
    {
        [$em] = $this->fixture();

        $tool = new SuggestFeedStructureTool(self::getContainer()->get(FeedAssistPort::class));
        self::assertSame(ToolKind::Read, $tool->kind());

        $result = $tool->execute([
            'template' => 'google_shopping',
            'object_type_code' => 'product',
            'sample_limit' => 3,
        ], $this->context());

        self::assertSame('google_shopping', $result['template']);
        self::assertSame('rss', $result['root']);
        self::assertIsArray($result['slots']);
        self::assertNotEmpty($result['slots']);
        self::assertIsString($result['xml']);
        self::assertStringContainsString('<rss', $result['xml']);
        self::assertIsArray($result['health'], 'the health report tells the user which required slots are missing');
        self::assertSame(1, $result['sample_count']);
    }

    #[Test]
    public function unknownTemplateIsRefused(): void
    {
        $this->fixture();

        $tool = new SuggestFeedStructureTool(self::getContainer()->get(FeedAssistPort::class));

        $this->expectException(InvalidArgumentException::class);
        $tool->execute(['template' => 'allegro-2049'], $this->context());
    }

    #[Test]
    public function generateFeedWithoutIdListsFeeds(): void
    {
        $this->fixture();

        $tool = new GenerateFeedTool(self::getContainer()->get(FeedAssistPort::class));
        self::assertSame(ToolKind::Action, $tool->kind());

        $result = $tool->execute([], $this->context());

        self::assertSame([], $result['feeds'], 'no feeds configured yet - the model must ask the user');
        self::assertArrayHasKey('note', $result);
    }

    /**
     * @return array{0: EntityManagerInterface}
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
        $object = new CatalogObject($type, 'FEED-1');
        $object->updateAttributeIndex(['name' => ['value' => 'Feed produkt'], 'sku' => ['value' => 'FEED-1']]);
        $em->persist($object);
        $em->flush();

        return [$em];
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
