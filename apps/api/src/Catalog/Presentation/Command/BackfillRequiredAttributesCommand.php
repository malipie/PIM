<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Command;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * #1416 — backfill for "dirty" legacy records left behind by #1350.
 *
 * Required-attribute enforcement only fires on the next SAVE of an
 * object, so rows created before the rule may sit with an empty value
 * forever. This command finds every (object, required attribute) pair —
 * scoped through the `object_type_attributes` junction — whose global
 * ObjectValue is missing or empty, and:
 *
 *  - text-like types (text / textarea / wysiwyg) get the
 *    "Brak danych" placeholder stamped with `Provenance::Import` so the
 *    UI badge distinguishes it from operator input,
 *  - every other type (number / select / boolean / date / …) is only
 *    REPORTED — no value can be guessed, the operator decides per row.
 *
 * Default mode is --dry-run-like (report only); pass --apply to write.
 * Memory shape mirrors RecalculateCompletenessCommand: toIterable() +
 * EntityManager::clear() every 200 rows (FrankenPHP worker rule).
 */
#[AsCommand(
    name: 'pim:catalog:backfill-required',
    description: 'Fill text-like required attributes with a placeholder on legacy records; report the rest.',
)]
final class BackfillRequiredAttributesCommand extends Command
{
    private const int CLEAR_EVERY = 200;
    private const string PLACEHOLDER = 'Brak danych';

    /** @var list<AttributeType> */
    private const array TEXT_LIKE = [
        AttributeType::Text,
        AttributeType::Textarea,
        AttributeType::Wysiwyg,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ObjectValueRepositoryInterface $values,
        private readonly TenantScopeBinder $tenantScope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'tenant',
                null,
                InputOption::VALUE_REQUIRED,
                'Tenant code to scope the backfill. Required.',
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Persist the placeholder writes. Without it the command only reports.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantCode = $input->getOption('tenant');
        if (!\is_string($tenantCode) || '' === $tenantCode) {
            $io->error('--tenant is required.');

            return Command::INVALID;
        }
        /** @var bool $apply */
        $apply = $input->getOption('apply');
        $io->title(\sprintf(
            'Backfill required attributes — tenant=%s, mode=%s',
            $tenantCode,
            $apply ? 'APPLY' : 'dry-run (report only)',
        ));

        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['code' => $tenantCode]);
        if (!$tenant instanceof Tenant) {
            $io->error(\sprintf('Tenant "%s" not found.', $tenantCode));

            return Command::FAILURE;
        }
        $tenantId = $tenant->getId();
        // CLI entry point — TenantAssignmentListener needs the current
        // tenant to stamp fresh ObjectValue rows. Re-set after every
        // EntityManager::clear() — a detached tenant reads as a "new
        // entity" to the UnitOfWork.
        $refreshTenantContext = function () use ($tenantId): void {
            $managedTenant = $this->em->getReference(Tenant::class, $tenantId);
            \assert($managedTenant instanceof Tenant);
            $this->tenantScope->bind($managedTenant);
        };
        $refreshTenantContext();

        $requiredQuery = $this->em->createQuery(
            'SELECT a FROM '.Attribute::class.' a JOIN a.tenant t'
            .' WHERE a.isRequired = true AND t.code = :tenantCode',
        );
        $requiredQuery->setParameter('tenantCode', $tenantCode);
        /** @var list<Attribute> $requiredAttributes */
        $requiredAttributes = $requiredQuery->getResult();
        if ([] === $requiredAttributes) {
            $io->success('No required attributes on this tenant — nothing to backfill.');

            return Command::SUCCESS;
        }

        $filledTotal = 0;
        $reportedTotal = 0;
        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $reportRows */
        $reportRows = [];

        foreach ($requiredAttributes as $attribute) {
            $attributeId = $attribute->getId();
            $attributeCode = $attribute->getCode();
            $attributeType = $attribute->getType();
            $isTextLike = \in_array($attributeType, self::TEXT_LIKE, true);

            $otIdsQuery = $this->em->createQuery(
                'SELECT IDENTITY(j.objectType) FROM '.ObjectTypeAttribute::class.' j'
                .' WHERE j.attribute = :attribute',
            );
            $otIdsQuery->setParameter('attribute', $attributeId, 'uuid');
            /** @var list<array{1: string}> $otRows */
            $otRows = $otIdsQuery->getScalarResult();
            $otIds = array_map(static fn (array $row): string => $row[1], $otRows);
            if ([] === $otIds) {
                continue;
            }

            $io->section(\sprintf(
                'Attribute "%s" (%s) — attached to %d ObjectType(s)',
                $attributeCode,
                $attributeType->value,
                \count($otIds),
            ));

            $objectsQuery = $this->em->createQuery(
                'SELECT o FROM '.CatalogObject::class.' o JOIN o.tenant t'
                .' WHERE IDENTITY(o.objectType) IN (:otIds) AND t.code = :tenantCode',
            );
            $objectsQuery->setParameter('otIds', $otIds);
            $objectsQuery->setParameter('tenantCode', $tenantCode);

            $processed = 0;
            $filled = 0;
            $reported = 0;
            /** @var iterable<int, CatalogObject> $iterable */
            $iterable = $objectsQuery->toIterable();
            foreach ($iterable as $object) {
                // Re-attach the attribute after a clear() detached it.
                $managedAttribute = $this->em->getReference(Attribute::class, $attributeId);
                \assert($managedAttribute instanceof Attribute);

                $existing = $this->values->findOneByScope($object, $managedAttribute, null, null);
                $isEmpty = !$existing instanceof ObjectValue || self::isEmptyEnvelope($existing->getValue());
                if (!$isEmpty) {
                    continue;
                }

                if ($isTextLike) {
                    if ($apply) {
                        if ($existing instanceof ObjectValue) {
                            $existing->updateValue(['value' => self::PLACEHOLDER]);
                            $existing->changeProvenance(Provenance::Import);
                            $this->values->save($existing);
                        } else {
                            $this->values->save(new ObjectValue(
                                object: $object,
                                attribute: $managedAttribute,
                                value: ['value' => self::PLACEHOLDER],
                                provenance: Provenance::Import,
                            ));
                        }
                    }
                    ++$filled;
                } else {
                    ++$reported;
                    if (\count($reportRows) < 100) {
                        $reportRows[] = [
                            $object->getId()->toRfc4122(),
                            $object->getCode(),
                            $attributeCode,
                            $attributeType->value,
                        ];
                    }
                }

                if (0 === ++$processed % self::CLEAR_EVERY) {
                    if ($apply) {
                        $this->em->flush();
                    }
                    $this->em->clear();
                    $refreshTenantContext();
                }
            }

            if ($apply) {
                $this->em->flush();
            }
            $this->em->clear();
            $refreshTenantContext();

            $io->writeln(\sprintf(
                '  %d object(s) scanned, %d placeholder(s) %s, %d non-text gap(s) reported',
                $processed,
                $filled,
                $apply ? 'written' : 'would be written',
                $reported,
            ));
            $filledTotal += $filled;
            $reportedTotal += $reported;
        }

        if ([] !== $reportRows) {
            $io->section('Non-text gaps (operator decision needed, first 100)');
            $io->table(['object id', 'object code', 'attribute', 'type'], $reportRows);
        }

        $io->success(\sprintf(
            '%s: %d placeholder(s) %s, %d gap(s) reported across %d required attribute(s).',
            $apply ? 'APPLY' : 'DRY-RUN',
            $filledTotal,
            $apply ? 'written' : 'pending (re-run with --apply)',
            $reportedTotal,
            \count($requiredAttributes),
        ));

        return Command::SUCCESS;
    }

    /**
     * Same emptiness rule as ObjectAttributesUpserter::isEmptyEnvelope —
     * null / '' / [] leaves all the way down; booleans and zeros count
     * as values.
     */
    private static function isEmptyEnvelope(mixed $value): bool
    {
        if (null === $value || '' === $value || [] === $value) {
            return true;
        }
        if (\is_array($value)) {
            foreach ($value as $leaf) {
                if (!self::isEmptyEnvelope($leaf)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
