<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\BulkEditValuesTool;
use App\Catalog\Contracts\Command\BulkEditValuesPort;
use App\Catalog\Contracts\Command\ValueEditProposal;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BulkEditValuesToolTest extends TestCase
{
    #[Test]
    public function defaultsSetIntentToOverwrite(): void
    {
        $port = new RecordingValueEditPort(new ValueEditProposal(Uuid::v7(), 1, 1, 0, []));
        $tool = new BulkEditValuesTool($port, $this->pendingChanges());

        $result = $tool->execute(['changes' => ['width' => ['value' => 300, 'unit' => 'mm']]], $this->context());

        self::assertSame('overwrite', $port->lastMode);
        self::assertSame('overwrite', $result['mode']);
        self::assertStringContainsString('overwrite', $tool->description());
        $schema = $tool->parametersSchema();
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['properties']['mode']);
        self::assertIsString($schema['properties']['mode']['description']);
        self::assertStringContainsString('default for set/change/fix', $schema['properties']['mode']['description']);
    }

    #[Test]
    public function explicitOnlyEmptyReportsStructuredReasonsAndCurrentExamples(): void
    {
        $example = [
            'object_id' => Uuid::v7()->toRfc4122(),
            'object_code' => 'SKU-1',
            'attribute_code' => 'width',
            'current_value' => ['value' => 210, 'unit' => 'mm'],
        ];
        $proposal = new ValueEditProposal(
            batchId: Uuid::v7(),
            affectedObjects: 0,
            materializedChanges: 0,
            skippedExisting: 1,
            rejected: [['code' => 'secret', 'reason' => 'Attribute is outside your edit permissions.']],
            skippedExistingExamples: [$example],
            selectorRejected: 2,
            selectorMatchedObjects: 1,
            permissionRejectedAttributes: 1,
            mode: 'only_empty',
        );
        $port = new RecordingValueEditPort($proposal);
        $tool = new BulkEditValuesTool($port, $this->pendingChanges());

        $result = $tool->execute(['changes' => ['width' => 300], 'mode' => 'only_empty'], $this->context());

        self::assertSame('only_empty', $port->lastMode);
        self::assertIsArray($result['skip_reasons']);
        $skipReasons = $result['skip_reasons'];
        self::assertIsArray($skipReasons['existing_values']);
        self::assertIsArray($skipReasons['selector']);
        self::assertIsArray($skipReasons['permissions']);
        self::assertIsArray($skipReasons['existing_values']['examples']);
        self::assertSame($example, $skipReasons['existing_values']['examples'][0]);
        self::assertSame(2, $skipReasons['selector']['rejected_object_ids']);
        self::assertSame(1, $skipReasons['permissions']['rejected_attributes']);
        self::assertArrayNotHasKey('pending_change_batch_id', $result);
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
                return 0;
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

final class RecordingValueEditPort implements BulkEditValuesPort
{
    public ?string $lastMode = null;

    public function __construct(private readonly ValueEditProposal $proposal)
    {
    }

    public function materializeValueEdits(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        array $changes,
        string $mode,
        ?array $selectedIds = null,
    ): ValueEditProposal {
        $this->lastMode = $mode;

        return $this->proposal;
    }

    public function materializeArithmeticEdits(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        string $attrCode,
        string $operator,
        float $operand,
        ?array $selectedIds = null,
    ): ValueEditProposal {
        return $this->proposal;
    }
}
