<?php

declare(strict_types=1);

namespace App\Export\Application;

use App\Export\Contracts\ExportTriggerPort;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Export\Domain\Message\RunExportMessage;
use App\Export\Domain\Repository\ExportSessionRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-06 (#1966) — adapter behind Export\Contracts: builds an
 * ExportSession exactly like the admin's async path (source=agent for
 * provenance) and dispatches the SAME RunExportMessage — the existing
 * ExportJobHandler streams the file to storage and publishes progress
 * on the export Mercure topic. No new export machinery.
 */
final readonly class AgentExportTrigger implements ExportTriggerPort
{
    public function __construct(
        private ExportSessionRepositoryInterface $sessions,
        private Connection $connection,
        private TenantContext $tenantContext,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function trigger(
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        array $columns,
        string $format,
    ): Uuid {
        $exportFormat = ExportFormat::tryFrom($format);
        if (!$exportFormat instanceof ExportFormat || ExportFormat::Xml === $exportFormat) {
            throw new InvalidArgumentException(\sprintf('Unsupported export format "%s" (use xlsx or csv).', $format));
        }
        if ([] === $columns) {
            throw new InvalidArgumentException('No columns given.');
        }

        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot trigger an export without a current tenant.');
        }

        // tenant-safe: explicit tenant_id predicate; a plain id lookup keeps
        // Export free of a Catalog\Domain class dependency (Deptrac).
        $objectTypeId = $this->connection->fetchOne(
            'SELECT ot.id FROM object_types ot WHERE ot.tenant_id = :tenant AND ot.code = :code',
            ['tenant' => $tenant->getId()->toRfc4122(), 'code' => $objectTypeCode],
        );
        if (!\is_string($objectTypeId)) {
            throw new InvalidArgumentException(\sprintf('Unknown object type "%s".', $objectTypeCode));
        }

        $session = new ExportSession(
            userId: $userId,
            source: ExportSource::Agent,
            format: $exportFormat,
            targetScope: [] === $filterDsl ? ExportTargetScope::All : ExportTargetScope::Filter,
            selectedColumns: $columns,
            filterSnapshot: [] === $filterDsl ? null : $filterDsl,
            objectTypeId: Uuid::fromString($objectTypeId),
        );
        $session->assignTenant($tenant);
        $this->sessions->save($session);

        $this->messageBus->dispatch(new RunExportMessage($session->getId(), $tenant->getId()));

        return $session->getId();
    }
}
