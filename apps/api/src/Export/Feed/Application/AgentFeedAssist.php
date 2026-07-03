<?php

declare(strict_types=1);

namespace App\Export\Feed\Application;

use App\Export\Contracts\FeedAssistPort;
use App\Export\Contracts\FeedProductScope;
use App\Export\Feed\Application\Preview\FeedPreviewService;
use App\Export\Feed\Application\Template\FeedProfileFactory;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Mapping\FeedFieldMapping;
use App\Export\Feed\Domain\Message\RunFeedMessage;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P7-01 (#1981) — adapter behind Export\Contracts\FeedAssistPort:
 * suggest-structure evaluates a BUILT-IN template through the same
 * FeedProfileFactory + FeedPreviewService the wizard uses (the engine
 * answers, never a bespoke serializer - boundary §5.6); regenerate
 * mirrors the "Generuj teraz" path (FeedRun + RunFeedMessage on the
 * import queue).
 */
final readonly class AgentFeedAssist implements FeedAssistPort
{
    public function __construct(
        private FeedProfileFactory $profileFactory,
        private FeedPreviewService $preview,
        private FeedProfileRepositoryInterface $feeds,
        private FeedRunRepositoryInterface $runs,
        private MessageBusInterface $messageBus,
        private TenantContext $tenantContext,
        private Connection $connection,
    ) {
    }

    public function suggestStructure(string $templateKind, string $objectTypeCode, array $filterDsl, int $sampleLimit = 5): array
    {
        $kind = FeedTemplateKind::tryFrom($templateKind);
        if (null === $kind) {
            throw new InvalidArgumentException(\sprintf(
                'Unknown feed template "%s" (use %s).',
                $templateKind,
                implode(', ', array_map(static fn (FeedTemplateKind $k): string => $k->value, FeedTemplateKind::cases())),
            ));
        }

        $tenant = $this->tenant();

        // tenant-safe: explicit tenant_id predicate; a plain id lookup keeps
        // the Feed area free of a Catalog class dependency (Deptrac).
        $objectTypeId = $this->connection->fetchOne(
            'SELECT ot.id FROM object_types ot WHERE ot.tenant_id = :tenant AND ot.code = :code',
            ['tenant' => $tenant->getId()->toRfc4122(), 'code' => $objectTypeCode],
        );
        if (!\is_string($objectTypeId)) {
            throw new InvalidArgumentException(\sprintf('Unknown object type "%s".', $objectTypeCode));
        }

        // A throwaway draft profile straight from the template — the
        // suggestion IS the template's defaults evaluated on real data.
        $profile = $this->profileFactory->fromTemplate(
            kind: $kind,
            code: 'agent-draft-'.$kind->value,
            name: 'Agent draft ('.$kind->value.')',
            objectTypeId: Uuid::fromString($objectTypeId),
        );

        $descriptor = FeedDescriptor::fromArray($profile->getDescriptor());
        $mappings = FeedFieldMapping::listFromArray($profile->getFieldMappings());

        $attributeCodes = [];
        $slots = [];
        foreach ($mappings as $mapping) {
            $source = match ($mapping->sourceKind) {
                'attribute' => 'attribute:'.($mapping->sourceRef ?? '?'),
                'constant' => 'constant:'.($mapping->sourceValue ?? ''),
                null => 'unmapped',
                default => $mapping->sourceKind.':'.($mapping->sourceRef ?? $mapping->sourceValue ?? ''),
            };
            $slots[] = ['slot' => $mapping->slot, 'source' => $source];
            if ('attribute' === $mapping->sourceKind && null !== $mapping->sourceRef) {
                $attributeCodes[] = $mapping->sourceRef;
            }
        }

        $scope = new FeedProductScope(
            objectTypeId: Uuid::fromString($objectTypeId),
            attributeCodes: array_values(array_unique($attributeCodes)),
            filter: [] === $filterDsl ? null : $filterDsl,
        );

        $sample = $this->preview->preview($descriptor, $mappings, $scope, [], max(1, min($sampleLimit, 25)));

        return [
            'template' => $kind->value,
            'root' => $descriptor->rootElement,
            'slots' => $slots,
            'sample_count' => $sample['sample_count'],
            'xml' => $sample['xml'],
            'health' => $sample['health'],
        ];
    }

    public function listFeeds(): array
    {
        $feeds = [];
        foreach ($this->feeds->findByTenant($this->tenant()) as $feed) {
            $feeds[] = [
                'id' => $feed->getId()->toRfc4122(),
                'code' => $feed->getCode(),
                'name' => $feed->getName(),
            ];
        }

        return $feeds;
    }

    public function regenerateFeed(Uuid $feedId): Uuid
    {
        $feed = $this->feeds->findById($feedId);
        if (null === $feed) {
            throw new InvalidArgumentException(\sprintf('Unknown feed "%s".', $feedId->toRfc4122()));
        }

        $run = new FeedRun($feed->getId(), FeedRunTrigger::Manual);
        $this->runs->save($run);
        $this->messageBus->dispatch(new RunFeedMessage($run->getId(), $this->tenant()->getId()));

        return $run->getId();
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot assist with feeds without a current tenant.');
        }

        return $tenant;
    }
}
