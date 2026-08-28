<?php

declare(strict_types=1);

namespace App\Import\Application\Service\Structural;

use App\Catalog\Application\Command\CreateAttribute\CreateAttributeCommand;
use App\Catalog\Application\Command\UpdateAttribute\UpdateAttributeCommand;
use App\Catalog\Application\ObjectTypeService;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Import\Application\Service\ImportColumnGrammar;
use App\Import\Application\Service\MultiValueSplitter;
use App\Import\Domain\Enum\ImportErrorType;
use App\Import\Domain\Enum\ImportLogLevel;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * Creates or updates one Attribute definition from a structural-import row,
 * the mirror of the `attributes_groups` export. Upserts by `code` (the natural
 * key, unique per tenant): an unknown code dispatches CreateAttributeCommand, a
 * known one UpdateAttributeCommand. Empty optional cells are left untouched
 * (operator decision); the `object_types` and `groups` cells re-attach the
 * attribute to its modules and groups additively (re-import never detaches).
 * Unknown object-type / group codes are skipped with a warning, never failing
 * the row.
 */
final readonly class AttributeImportCreator
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private AttributeRepositoryInterface $attributes,
        private AttributeGroupRepositoryInterface $groups,
        private ObjectTypeRepositoryInterface $objectTypes,
        private ObjectTypeService $objectTypeService,
        private AttributeOptionImporter $optionImporter,
        private ImportColumnGrammar $grammar,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, string|null> $cells header => raw cell value
     */
    public function create(int $rowNumber, array $cells, Tenant $tenant): StructuralImportRowResult
    {
        $result = new StructuralImportRowResult();

        $code = trim($cells['code'] ?? '');
        if ('' === $code) {
            $result->setOutcome(StructuralImportRowResult::OUTCOME_ERROR);
            $result->log(ImportLogLevel::Error, 'Wiersz pominięty: brak wymaganej kolumny "code".', ImportErrorType::MissingRequired->value, 'code');

            return $result;
        }
        $result->code = $code;

        $label = $this->extractLocalized($cells, 'label', $tenant);
        $help = $this->extractLocalized($cells, 'help', $tenant);
        $validationRules = $this->parseJsonObject($cells['validation_rules'] ?? null, $rowNumber, $result);
        $options = $this->parseOptions($cells['options'] ?? null, $result);
        $groupCodes = MultiValueSplitter::split($cells['groups'] ?? '');
        $objectTypeCodes = MultiValueSplitter::split($cells['object_types'] ?? '');

        $existing = $this->attributes->findByCode($code, $tenant);

        try {
            $attribute = null === $existing
                ? $this->createAttribute($code, $cells, $label, $help, $validationRules, $tenant, $result)
                : $this->updateAttribute($existing, $cells, $label, $help, $validationRules, $result);
        } catch (Throwable $exception) {
            $result->setOutcome(StructuralImportRowResult::OUTCOME_ERROR);
            $result->log(ImportLogLevel::Error, \sprintf('Atrybut "%s": %s', $code, $exception->getMessage()), ImportErrorType::InvalidValue->value, 'code', $code);

            return $result;
        }

        if (!$attribute instanceof Attribute) {
            return $result;
        }

        $this->attachGroups($attribute, $groupCodes, $tenant, $result);
        $this->assignObjectTypes($attribute, $objectTypeCodes, $tenant, $result);
        if ($attribute->usesOptions() && [] !== $options) {
            $this->optionImporter->sync($attribute, $options);
        }

        return $result;
    }

    /**
     * @param array<string, string|null> $cells
     * @param array<string, string>      $label
     * @param array<string, string>      $help
     * @param array<string, mixed>       $validationRules
     */
    private function createAttribute(
        string $code,
        array $cells,
        array $label,
        array $help,
        array $validationRules,
        Tenant $tenant,
        StructuralImportRowResult $result,
    ): ?Attribute {
        $rawType = trim($cells['type'] ?? '');
        $type = AttributeType::tryFrom($rawType);
        if (!$type instanceof AttributeType) {
            $result->setOutcome(StructuralImportRowResult::OUTCOME_ERROR);
            $result->log(ImportLogLevel::Error, \sprintf('Atrybut "%s": nieznany typ "%s".', $code, $rawType), ImportErrorType::InvalidType->value, 'type', $rawType);

            return null;
        }

        $command = new CreateAttributeCommand(
            code: $code,
            label: $label,
            type: $type->value,
            help: [] === $help ? null : $help,
            localizable: $this->boolCell($cells['is_localizable'] ?? null) ?? false,
            scopable: $this->boolCell($cells['is_scopable'] ?? null) ?? false,
            required: $this->boolCell($cells['is_required'] ?? null) ?? false,
            filterable: $this->boolCell($cells['is_filterable'] ?? null) ?? false,
            validationRules: $validationRules,
        );
        $newId = $this->dispatch($command);
        $attribute = $newId instanceof Uuid ? $this->attributes->findById($newId) : $this->attributes->findByCode($code, $tenant);
        $result->setOutcome(StructuralImportRowResult::OUTCOME_CREATED);

        return $attribute;
    }

    /**
     * @param array<string, string|null> $cells
     * @param array<string, string>      $label
     * @param array<string, string>      $help
     * @param array<string, mixed>       $validationRules
     */
    private function updateAttribute(
        Attribute $existing,
        array $cells,
        array $label,
        array $help,
        array $validationRules,
        StructuralImportRowResult $result,
    ): Attribute {
        // Empty cells leave the value untouched (operator decision): only pass
        // a field when the file actually carried a value for it.
        $isSystem = $existing->isSystem();
        $localizable = $isSystem ? null : $this->boolCell($cells['is_localizable'] ?? null);
        $scopable = $isSystem ? null : $this->boolCell($cells['is_scopable'] ?? null);
        $required = $isSystem ? null : $this->boolCell($cells['is_required'] ?? null);
        $filterable = $isSystem ? null : $this->boolCell($cells['is_filterable'] ?? null);
        $rules = $isSystem ? null : ([] === $validationRules ? null : $validationRules);

        if ($isSystem && $this->carriesStructuralChange($cells)) {
            $result->log(ImportLogLevel::Warning, \sprintf('Atrybut systemowy "%s": zmieniono tylko etykietę/opis, pola strukturalne pominięto.', $existing->getCode()), ImportErrorType::InvalidValue->value, 'code', $existing->getCode());
        }

        $command = new UpdateAttributeCommand(
            id: $existing->getId(),
            label: [] === $label ? null : $label,
            help: [] === $help ? null : $help,
            localizable: $localizable,
            scopable: $scopable,
            required: $required,
            filterable: $filterable,
            validationRules: $rules,
        );
        $this->dispatch($command);
        $result->setOutcome(StructuralImportRowResult::OUTCOME_UPDATED);

        return $existing;
    }

    /**
     * Additive group attachment: ensure an AttributeGroupAttribute junction for
     * every known group code; never detaches. Unknown codes warn and skip.
     *
     * @param list<string> $groupCodes
     */
    private function attachGroups(Attribute $attribute, array $groupCodes, Tenant $tenant, StructuralImportRowResult $result): void
    {
        if ([] === $groupCodes) {
            return;
        }
        $junctions = $this->em->getRepository(AttributeGroupAttribute::class);
        $changed = false;
        foreach ($groupCodes as $groupCode) {
            $group = $this->groups->findByCode($groupCode, $tenant);
            if (!$group instanceof AttributeGroup) {
                $result->log(ImportLogLevel::Warning, \sprintf('Pominięto nieznaną grupę "%s".', $groupCode), ImportErrorType::InvalidValue->value, 'groups', $groupCode);

                continue;
            }
            $existing = $junctions->findOneBy(['attributeGroup' => $group, 'attribute' => $attribute]);
            if (null === $existing) {
                $this->em->persist(new AttributeGroupAttribute($group, $attribute));
                $changed = true;
            }
        }
        if ($changed) {
            $this->em->flush();
        }
    }

    /**
     * Idempotent ObjectType assignment via the shared service; unknown codes
     * warn and skip.
     *
     * @param list<string> $objectTypeCodes
     */
    private function assignObjectTypes(Attribute $attribute, array $objectTypeCodes, Tenant $tenant, StructuralImportRowResult $result): void
    {
        foreach ($objectTypeCodes as $objectTypeCode) {
            $objectType = $this->objectTypes->findByCode($objectTypeCode, $tenant);
            if (!$objectType instanceof ObjectType) {
                $result->log(ImportLogLevel::Warning, \sprintf('Pominięto nieznany typ obiektu "%s".', $objectTypeCode), ImportErrorType::InvalidValue->value, 'object_types', $objectTypeCode);

                continue;
            }
            $this->objectTypeService->assignAttribute($objectType, $attribute);
        }
    }

    private function dispatch(object $command): mixed
    {
        try {
            $envelope = $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $exception) {
            throw $exception->getPrevious() ?? $exception;
        }
        $stamp = $envelope->last(HandledStamp::class);

        return $stamp instanceof HandledStamp ? $stamp->getResult() : null;
    }

    /**
     * @param array<string, string|null> $cells
     *
     * @return array<string, string>
     */
    private function extractLocalized(array $cells, string $base, Tenant $tenant): array
    {
        $out = [];
        foreach ($cells as $header => $value) {
            if (null === $value || '' === trim($value)) {
                continue;
            }
            $parsed = $this->grammar->parse($header, $tenant);
            if ($parsed->base === $base && null !== $parsed->locale) {
                $out[$parsed->locale] = trim($value);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonObject(?string $raw, int $rowNumber, StructuralImportRowResult $result): array
    {
        $raw = null === $raw ? '' : trim($raw);
        if ('' === $raw || '{}' === $raw || '[]' === $raw) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $result->log(ImportLogLevel::Warning, \sprintf('Wiersz %d: nieprawidłowy JSON w "validation_rules" — pominięto.', $rowNumber), ImportErrorType::InvalidValue->value, 'validation_rules', $raw);

            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @return list<array{code: string, label: array<string, string>}>
     */
    private function parseOptions(?string $raw, StructuralImportRowResult $result): array
    {
        $raw = null === $raw ? '' : trim($raw);
        if ('' === $raw || '[]' === $raw) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $result->log(ImportLogLevel::Warning, 'Nieprawidłowy JSON w "options" — pominięto wartości słownikowe.', ImportErrorType::InvalidValue->value, 'options', $raw);

            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry) || !isset($entry['code']) || !\is_scalar($entry['code'])) {
                continue;
            }
            $optionCode = trim((string) $entry['code']);
            if ('' === $optionCode) {
                continue;
            }
            $label = [];
            if (isset($entry['label']) && \is_array($entry['label'])) {
                foreach ($entry['label'] as $locale => $text) {
                    if (\is_scalar($text)) {
                        $label[(string) $locale] = (string) $text;
                    }
                }
            }
            $out[] = ['code' => $optionCode, 'label' => $label];
        }

        return $out;
    }

    /**
     * @param array<string, string|null> $cells
     */
    private function carriesStructuralChange(array $cells): bool
    {
        foreach (['is_localizable', 'is_scopable', 'is_required', 'is_filterable', 'validation_rules'] as $key) {
            $value = $cells[$key] ?? null;
            if (null !== $value && '' !== trim($value)) {
                return true;
            }
        }

        return false;
    }

    private function boolCell(?string $raw): ?bool
    {
        if (null === $raw) {
            return null;
        }
        $raw = strtolower(trim($raw));
        if ('' === $raw) {
            return null;
        }

        return \in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
