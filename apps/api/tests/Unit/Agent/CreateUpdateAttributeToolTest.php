<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\CreateUpdateAttributeTool;
use App\Agent\Application\Tool\ToolKind;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #2279 — a group-less attribute commits fine but stays a library entry
 * with no object-type link, so it never shows on products. The tool must
 * make the model tell the user the attribute is unattached (otherwise the
 * create looks like it silently did nothing).
 */
final class CreateUpdateAttributeToolTest extends TestCase
{
    #[Test]
    public function metadataDeclaresASchemaToolGuardedByModeling(): void
    {
        $tool = new CreateUpdateAttributeTool($this->pendingChanges());

        self::assertSame('create_update_attribute', $tool->name());
        self::assertSame('modeling.attributes.add_edit', $tool->requiredPermission());
        self::assertSame(ToolKind::Schema, $tool->kind());
        self::assertStringContainsStringIgnoringCase('library', $tool->description());
    }

    #[Test]
    public function agrouplessAttributeWarnsItIsUnattached(): void
    {
        $tool = new CreateUpdateAttributeTool($this->pendingChanges());

        $result = $tool->execute(
            ['code' => 'test_from_block', 'type' => 'text', 'label' => ['pl' => 'Test']],
            $this->context(),
        );

        self::assertArrayHasKey('pending_change_batch_id', $result);
        self::assertFalse($result['attached_to_groups']);
        $note = $result['note'];
        self::assertIsString($note);
        self::assertStringContainsStringIgnoringCase('library', $note);
        self::assertStringContainsStringIgnoringCase('will not appear on any product', $note);
    }

    #[Test]
    public function anAttributeWithGroupsDoesNotWarn(): void
    {
        $tool = new CreateUpdateAttributeTool($this->pendingChanges());

        $result = $tool->execute(
            ['code' => 'weight', 'type' => 'number', 'label' => ['pl' => 'Waga'], 'groups' => ['dimensions']],
            $this->context(),
        );

        self::assertTrue($result['attached_to_groups']);
        $note = $result['note'];
        self::assertIsString($note);
        self::assertStringNotContainsStringIgnoringCase('will not appear on any product', $note);
    }

    private function context(): AgentToolContext
    {
        return new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), []);
    }

    private function pendingChanges(): PendingChangesPort
    {
        return new class implements PendingChangesPort {
            public function materialize(Uuid $batchId, string $provenance, iterable $drafts): int
            {
                return 1;
            }

            public function listBatch(Uuid $batchId, int $limit = 100, int $offset = 0): array
            {
                return [];
            }

            public function iterateBatch(Uuid $batchId): iterable
            {
                return [];
            }

            public function countBatch(Uuid $batchId): int
            {
                return 0;
            }

            public function accept(Uuid $batchId): int
            {
                return 0;
            }

            public function reject(Uuid $batchId): int
            {
                return 0;
            }

            public function expire(Uuid $batchId): int
            {
                return 0;
            }

            public function annotate(Uuid $changeId, array $meta): void
            {
            }
        };
    }
}
