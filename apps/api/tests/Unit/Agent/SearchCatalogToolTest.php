<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\SearchCatalogTool;
use App\Agent\Application\Tool\ToolKind;
use App\Search\Contracts\CatalogQueryResult;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * AGENT-P2-01 (#1958) — the grounding tool: view-context filter applies
 * by default, explicit filter wins, per_page is capped, hits are the
 * minimal projection (no attribute map -> no per-attribute leak by
 * construction), and `degraded` carries an explicit do-not-fabricate
 * note for the model.
 */
final class SearchCatalogToolTest extends TestCase
{
    #[Test]
    public function viewContextFilterAppliesWhenModelPassesNone(): void
    {
        $port = $this->recordingPort(new CatalogQueryResult([], 1800, false));
        $tool = new SearchCatalogTool($port);

        $viewFilter = ['attr' => 'price', 'op' => 'IS EMPTY'];
        $result = $tool->execute([], $this->context(['filter_dsl' => $viewFilter]));

        self::assertSame(1800, $result['total_hits']);
        self::assertSame($viewFilter, $port->lastFilterDsl);
        self::assertSame('product', $port->lastKind);
    }

    #[Test]
    public function explicitFilterOverridesViewContext(): void
    {
        $port = $this->recordingPort(new CatalogQueryResult([], 3, false));
        $tool = new SearchCatalogTool($port);

        $explicit = ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'];
        $tool->execute(
            ['filter_dsl' => $explicit, 'object_kind' => 'category', 'per_page' => 500],
            $this->context(['filter_dsl' => ['attr' => 'price', 'op' => 'IS EMPTY']]),
        );

        self::assertSame($explicit, $port->lastFilterDsl);
        self::assertSame('category', $port->lastKind);
        self::assertSame(50, $port->lastPerPage, 'per_page must be capped at 50');
    }

    #[Test]
    public function hitsAreProjectedWithoutAttributeMap(): void
    {
        $hit = [
            'id' => 'obj-1',
            'code' => 'DEMO-100',
            'kind' => 'product',
            'status' => 'draft',
            'attributesIndexed' => [
                'name' => ['value' => 'Buty Air Max'],
                'secret_margin' => ['value' => 42.0],
            ],
            'completeness' => ['global' => 62],
        ];
        $tool = new SearchCatalogTool($this->recordingPort(new CatalogQueryResult([$hit], 1, false)));

        $result = $tool->execute([], $this->context());

        $hits = $result['hits'];
        self::assertIsArray($hits);
        self::assertSame(
            ['id' => 'obj-1', 'code' => 'DEMO-100', 'kind' => 'product', 'status' => 'draft', 'name' => 'Buty Air Max', 'completeness_pct' => 62],
            $hits[0],
        );
        self::assertStringNotContainsString(
            'secret_margin',
            json_encode($result, JSON_THROW_ON_ERROR),
            'the attribute map must never reach the model (field-level by construction)',
        );
    }

    #[Test]
    public function degradedEngineCarriesDoNotFabricateNote(): void
    {
        $tool = new SearchCatalogTool($this->recordingPort(new CatalogQueryResult([], 0, true)));

        $result = $tool->execute([], $this->context());

        self::assertTrue($result['degraded']);
        self::assertIsString($result['note']);
        self::assertStringContainsString('could not verify', $result['note']);
        self::assertSame(ToolKind::Read, $tool->kind());
        self::assertSame('object.read', $tool->requiredPermission());
    }

    /**
     * @param array<string, mixed> $viewContext
     */
    private function context(array $viewContext = []): AgentToolContext
    {
        return new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), $viewContext);
    }

    private function recordingPort(CatalogQueryResult $result): RecordingCatalogQueryPort
    {
        return new RecordingCatalogQueryPort($result);
    }
}
