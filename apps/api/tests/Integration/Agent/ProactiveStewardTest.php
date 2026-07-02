<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Proactive\ProactiveStewardScanner;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentMessage;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Application\ByokKeyManager;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_UNESCAPED_UNICODE;

/**
 * AGENT-P8-01 (#1983) — proactive data steward: STRICTLY opt-in (no
 * flag = no run), and with the flag on a seeded anomaly (a value 100x
 * above the median) plus a completeness gap open a findings run
 * (surface=proactive, awaiting_input) with the assistant report -
 * nothing commits, the approval gate still owns every write.
 */
final class ProactiveStewardTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function optOutMeansNoRun(): void
    {
        [$tenant] = $this->fixture(withKey: true, proactive: false);

        $run = $this->scanner()->scanTenant($tenant, Uuid::v7());

        self::assertNull($run, 'without the opt-in the scan must not open runs');
    }

    #[Test]
    public function anomalyAndGapOpenAFindingsRun(): void
    {
        [$tenant, $em] = $this->fixture(withKey: true, proactive: true);

        $run = $this->scanner()->scanTenant($tenant, Uuid::v7());

        self::assertNotNull($run, 'seeded anomaly + gap must open a findings run');
        self::assertSame(AgentRunSurface::Proactive, $run->getSurface());
        self::assertSame(AgentRunStatus::AwaitingInput, $run->getStatus());

        $messages = $em->getRepository(AgentMessage::class)->findBy(['run' => $run]);
        self::assertCount(1, $messages);
        $text = json_encode($messages[0]->getContent(), JSON_UNESCAPED_UNICODE);
        self::assertIsString($text);
        self::assertStringContainsString('Anomalia', $text);
        self::assertStringContainsString('price', $text);
        self::assertStringContainsString('Luka', $text);
        self::assertStringContainsString('ean', $text);

        // SEC: a report, not a write - no pending changes, no values touched.
        $pending = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_changes');
        self::assertSame(0, (int) (\is_scalar($pending) ? $pending : -1));
    }

    /**
     * @return array{0: Tenant, 1: EntityManagerInterface}
     */
    private function fixture(bool $withKey, bool $proactive): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        if ($withKey) {
            $manager = self::getContainer()->get(ByokKeyManager::class);
            $manager->setKey($tenant, 'sk-ant-api03-proactive-test');
            if ($proactive) {
                $manager->setProactiveScan($tenant, true);
            }
        }

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        // Completeness gap: `ean` required but missing everywhere.
        $type->updateCompletenessRules(['required' => ['ean', 'price']]);
        $em->persist($type);
        $price = new Attribute('price', ['en' => 'Price'], AttributeType::Number);
        $em->persist($price);
        $em->persist(new Attribute('ean', ['en' => 'EAN'], AttributeType::Text));

        // Anomaly: three sane prices and one 100 000 among ~100s.
        foreach ([100, 120, 90, 100_000] as $i => $value) {
            $object = new CatalogObject($type, 'SKU-'.$i);
            $em->persist($object);
            $em->persist(new ObjectValue($object, $price, ['value' => $value]));
        }
        $em->flush();

        return [$tenant, $em];
    }

    private function scanner(): ProactiveStewardScanner
    {
        $scanner = self::getContainer()->get('test.agent.proactive');
        \assert($scanner instanceof ProactiveStewardScanner);

        return $scanner;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
