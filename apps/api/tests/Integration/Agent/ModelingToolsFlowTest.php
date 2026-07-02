<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\CreateUpdateAttributeGroupTool;
use App\Agent\Application\Tool\CreateUpdateAttributeTool;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
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
 * AGENT-P5-02 (#1971) — point modeling by conversation: single
 * attribute / group proposals go through the same schema approval
 * channel; an existing code UPDATES (no duplicate), a new one CREATES;
 * an unknown type is refused so the model asks the user.
 */
final class ModelingToolsFlowTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function createThenUpdateAttributeUpsertsByCode(): void
    {
        [$em] = $this->fixture();

        // Create: group first, then the attribute attached to it.
        $groupResult = $this->groupTool()->execute([
            'code' => 'dimensions',
            'label' => ['pl' => 'Wymiary'],
        ], $this->context());
        self::assertIsString($groupResult['pending_change_batch_id']);
        $this->approveBatch($em, Uuid::fromString($groupResult['pending_change_batch_id']));

        $createResult = $this->attributeTool()->execute([
            'code' => 'weight',
            'type' => 'metric',
            'label' => ['pl' => 'Waga'],
            'groups' => ['dimensions'],
        ], $this->context());
        self::assertIsString($createResult['pending_change_batch_id']);
        $this->approveBatch($em, Uuid::fromString($createResult['pending_change_batch_id']));

        $conn = $em->getConnection();
        $type = $conn->fetchOne("SELECT type FROM attributes WHERE code = 'weight'");
        self::assertSame('metric', $type, 'approve must create the attribute');

        // Update: same code, new label - no duplicate, label patched.
        $updateResult = $this->attributeTool()->execute([
            'code' => 'weight',
            'label' => ['pl' => 'Waga netto', 'en' => 'Net weight'],
        ], $this->context());
        self::assertIsString($updateResult['pending_change_batch_id']);
        $this->approveBatch($em, Uuid::fromString($updateResult['pending_change_batch_id']));

        $count = $conn->fetchOne("SELECT COUNT(*) FROM attributes WHERE code = 'weight'");
        self::assertSame(1, (int) (\is_scalar($count) ? $count : -1), 'upsert by code must not duplicate');
        $labelJson = $conn->fetchOne("SELECT label::text FROM attributes WHERE code = 'weight'");
        self::assertIsString($labelJson);
        self::assertStringContainsString('Waga netto', $labelJson, 'approve must patch the label');
    }

    #[Test]
    public function unknownTypeIsRefusedWithoutMaterializing(): void
    {
        $this->fixture();

        $result = $this->attributeTool()->execute([
            'code' => 'mystery',
            'type' => 'hologram',
            'label' => ['pl' => 'Zagadka'],
        ], $this->context());

        self::assertArrayHasKey('error', $result);
        self::assertArrayNotHasKey('pending_change_batch_id', $result);
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

        // The structural cell grammar validates label.<locale> suffixes
        // against ACTIVE TenantLocale rows (Channel BC), not
        // Tenant::enabledLocales.
        $pl = new \App\Channel\Domain\Entity\Locale('pl_PL', 'Polski');
        $en = new \App\Channel\Domain\Entity\Locale('en_US', 'English');
        $em->persist($pl);
        $em->persist($en);
        $em->persist(new \App\Channel\Domain\Entity\TenantLocale($pl, isDefault: true, tenant: $tenant));
        $em->persist(new \App\Channel\Domain\Entity\TenantLocale($en, tenant: $tenant));
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return [$em];
    }

    private function approveBatch(EntityManagerInterface $em, Uuid $batchId): void
    {
        // materialize()/previous commits cleared the EM - re-attach tenant.
        $context = self::getContainer()->get(TenantContext::class);
        $detached = $context->get();
        if ($detached instanceof Tenant) {
            $managed = $em->find(Tenant::class, $detached->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                $context->set($managed);
            }
        }

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'point modeling');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        $service = self::getContainer()->get('test.agent.approval');
        \assert($service instanceof AgentApprovalService);
        $service->approve($run->getId(), Uuid::v7());
    }

    private function attributeTool(): CreateUpdateAttributeTool
    {
        $tool = new CreateUpdateAttributeTool($this->pendingChanges());
        self::assertSame(ToolKind::Schema, $tool->kind());

        return $tool;
    }

    private function groupTool(): CreateUpdateAttributeGroupTool
    {
        return new CreateUpdateAttributeGroupTool($this->pendingChanges());
    }

    private function context(): AgentToolContext
    {
        $tenant = self::getContainer()->get(TenantContext::class)->get();
        \assert($tenant instanceof Tenant);

        return new AgentToolContext(Uuid::v7(), $tenant, []);
    }

    private function pendingChanges(): PendingChangesPort
    {
        return self::getContainer()->get(PendingChangesPort::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
