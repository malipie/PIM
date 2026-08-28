<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\Validation\AttributeValueValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Command\ContentValuePort;
use App\Catalog\Contracts\Command\ContentValueProposal;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * AICG-P3-01 (#2334, ADR-0030) — materializes one GENERATED text value
 * as a pending diff, never touching the catalog (the sibling of
 * BulkEditValuesMaterializer for the content tools):
 *
 *   1. target must be a text-family attribute (Text/Textarea/Wysiwyg) —
 *      generated prose has no business landing in a price;
 *   2. per-attribute + per-locale/channel RBAC BY USER ID (the same
 *      P1-02 seam bulk edits re-check) — refusals are data
 *      (ContentValueProposal::forbidden), mirroring the executor's
 *      "forbidden as tool_result" semantics;
 *   3. the SAME AttributeValueValidator manual edits use (max_length
 *      etc. from validation_rules);
 *   4. scope normalised like the write path will route it (locale only
 *      when localizable, channel only when scopable) so the before
 *      reading matches what the commit overwrites;
 *   5. before = the exact-scope ObjectValue row (not the overlay
 *      fallback — the diff must show what THIS row currently holds).
 *
 * The catalog write happens ONLY post-approval through
 * PendingBatchCommitter -> BatchValueWriter, which stamps
 * Provenance::Agent + the provenance_meta from `meta`.
 */
final readonly class ContentValueMaterializer implements ContentValuePort
{
    private const array TEXT_TYPES = [AttributeType::Text, AttributeType::Textarea, AttributeType::Wysiwyg];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private ObjectValueRepositoryInterface $values,
        private AttributeValueValidator $valueValidator,
        private UserScopedPermissionCheckerInterface $permissions,
        private ChannelResolverInterface $channels,
        private PendingChangesPort $pendingChanges,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function materializeGeneratedValue(
        Uuid $batchId,
        Uuid $userId,
        Uuid $objectId,
        string $attributeCode,
        string $generatedText,
        ?string $locale = null,
        ?string $channel = null,
        array $meta = [],
    ): ContentValueProposal {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot materialize generated content without a current tenant.');
        }

        $object = $this->entityManager->find(CatalogObject::class, $objectId);
        if (!$object instanceof CatalogObject) {
            return ContentValueProposal::invalid(\sprintf('Unknown object "%s".', $objectId->toRfc4122()));
        }

        $attribute = $this->entityManager->getRepository(Attribute::class)->findOneBy(['code' => $attributeCode]);
        if (!$attribute instanceof Attribute) {
            return ContentValueProposal::invalid(\sprintf('Unknown attribute "%s".', $attributeCode));
        }
        if (!\in_array($attribute->getType(), self::TEXT_TYPES, true)) {
            return ContentValueProposal::invalid(\sprintf(
                'Attribute "%s" is %s — generated content targets text attributes (text/textarea/wysiwyg) only.',
                $attributeCode,
                $attribute->getType()->value,
            ));
        }

        if (!$this->permissions->canEditAttribute($userId, $attribute->getId())) {
            return ContentValueProposal::forbidden(\sprintf('Attribute "%s" is outside your edit scope.', $attributeCode));
        }

        // Normalise the scope the way the write path routes it, so the
        // before reading matches the row the commit will overwrite.
        $scopeLocale = $attribute->isLocalizable() ? $locale : null;
        $scopeChannel = $attribute->isScopable() ? $channel : null;
        if (null !== $scopeLocale && !$this->permissions->canEditLocale($userId, $scopeLocale)) {
            return ContentValueProposal::forbidden(\sprintf('Locale "%s" is outside your edit scope.', $scopeLocale));
        }
        if (null !== $scopeChannel && !$this->permissions->canEditChannel($userId, $scopeChannel)) {
            return ContentValueProposal::forbidden(\sprintf('Channel "%s" is outside your edit scope.', $scopeChannel));
        }

        $channelId = null;
        if (null !== $scopeChannel) {
            $channelId = $this->channels->resolveId($scopeChannel, $tenant);
            if (null === $channelId) {
                return ContentValueProposal::invalid(\sprintf('Unknown channel "%s".', $scopeChannel));
            }
        }

        $after = ['value' => $generatedText];
        try {
            $errors = $this->valueValidator->validate($attribute, $after);
        } catch (Throwable $failure) {
            return ContentValueProposal::invalid('Validation failed: '.$failure->getMessage());
        }
        if ([] !== $errors) {
            return ContentValueProposal::invalid($errors[0]->message);
        }

        $current = $this->values->findOneByScope($object, $attribute, $channelId, $scopeLocale);
        $before = $current?->getValue();

        $this->pendingChanges->materialize($batchId, 'agent', [new PendingChangeDraft(
            changeType: PendingChangeType::Value,
            targetObjectId: $object->getId(),
            attributeCode: $attributeCode,
            scopeLocale: $scopeLocale,
            scopeChannel: $scopeChannel,
            before: $before,
            after: $after,
            meta: $meta,
        )]);

        return ContentValueProposal::materialized($before, $after, $scopeLocale, $scopeChannel);
    }
}
