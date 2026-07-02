<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\AggregateCatalogTool;
use App\Agent\Application\Tool\ToolKind;
use App\Search\Contracts\CatalogQueryResult;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P2-02 (#1959) — the count tool grounds "matched N" through the
 * same port as search: view-context filter by default, exact count via
 * a perPage=1 probe, degraded honesty, and an explicit no-statistics
 * note (do not estimate medians).
 */
final class AggregateCatalogToolTest extends TestCase
{
    #[Test]
    public function countsWithViewContextFilterByDefault(): void
    {
        $port = new RecordingCatalogQueryPort(new CatalogQueryResult([], 1800, false));
        $tool = new AggregateCatalogTool($port);

        $viewFilter = ['attr' => 'price', 'op' => 'IS EMPTY'];
        $result = $tool->execute([], $this->context(['filter_dsl' => $viewFilter]));

        self::assertSame(1800, $result['matched']);
        self::assertFalse($result['degraded']);
        $note = $result['note'];
        self::assertIsString($note);
        self::assertStringContainsString('do not estimate', $note);
        self::assertSame($viewFilter, $port->lastFilterDsl);
        self::assertSame(1, $port->lastPerPage, 'count probe must not fetch hit pages');
        self::assertSame(ToolKind::Read, $tool->kind());
        self::assertSame('object.read', $tool->requiredPermission());
    }

    #[Test]
    public function degradedEngineIsHonest(): void
    {
        $tool = new AggregateCatalogTool(new RecordingCatalogQueryPort(new CatalogQueryResult([], 0, true)));

        $result = $tool->execute(['filter_dsl' => ['attr' => 'brand', 'op' => '=', 'value' => 'Festo']], $this->context());

        self::assertTrue($result['degraded']);
        $note = $result['note'];
        self::assertIsString($note);
        self::assertStringContainsString('could not verify', $note);
    }

    /**
     * @param array<string, mixed> $viewContext
     */
    private function context(array $viewContext = []): AgentToolContext
    {
        return new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), $viewContext);
    }
}
