<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Catalog\Contracts\Query\ObjectTypeSummaryPort;
use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * #2881 — the write counterpart of {@see ObjectCollectionVoter}: the gate
 * for the poly-kind `POST /api/objects`.
 *
 * This is the endpoint the admin actually creates through. The product
 * detail form posts here rather than to the `/api/products` sugar path
 * (`use-product-detail-form.ts`, "poly-kind create: kind comes from
 * objectTypeId"), and multimedia has no create sugar path at all, so for
 * `kind=asset` this is the only way in. It was still gated by
 * `is_granted('CREATE', CatalogObject::class)`, which no PRD role can
 * satisfy — not even `tenant_owner`, whose template carries no legacy
 * `object.write`. A tenant created through the panel therefore could not
 * save a single object, which is the 403 the operator hit four times in
 * production.
 *
 * Same shape as the read side, one source of the kind apart: at POST time
 * the subject is the bare `CatalogObject` class string, so the kind comes
 * from the `objectTypeId` in the request body — the very field
 * {@see \App\Catalog\Infrastructure\ApiPlatform\State\CatalogObjectProcessor::expectedKindFor()}
 * uses to pick the discriminator. Authorising that kind therefore
 * authorises exactly the row the processor is about to write.
 *
 * The conditions that keep this a gate rather than a widening:
 *   1. no readable `objectTypeId` means the caller is asking to create
 *      "something, kind unknown", which keeps requiring the broad legacy
 *      `object.write` via {@see \App\Identity\Infrastructure\Security\CatalogObjectVoter}
 *      (affirmative strategy — pre-PRD principals are unaffected);
 *   2. a cross-tenant id resolves to null through the Doctrine
 *      TenantFilter and is indistinguishable from a typo — both deny;
 *   3. a role holding one kind's create permission must not reach another
 *      kind through this endpoint, which is what the `approver` /
 *      `viewer` cases in `ObjectWriteAccessTest` pin down.
 *
 * @extends Voter<string, string>
 */
final class ObjectCreateVoter extends Voter
{
    private const string ATTRIBUTE = 'CREATE_OBJECT';
    private const string SUBJECT = 'App\\Catalog\\Domain\\Entity\\CatalogObject';

    /**
     * Kind → the PRD §3.2 code(s) that let a role create that kind. Any one
     * of a list grants.
     *
     * Keyed by the kind's wire value rather than `ObjectKind` cases for the
     * same reason as {@see ObjectCollectionVoter}: Deptrac keeps
     * `Identity_Internals` out of Catalog's Domain (ADR-0013).
     *
     * `custom` maps to the generic ULV-04a verb (#985) instead of a
     * per-kind code, because every tenant-defined ObjectType shares
     * `kind=custom` and no single per-kind code can name one of them. That
     * verb is already seeded and already granted to `tenant_owner`,
     * `admin` and `catalog_manager` — and it is strictly narrower than the
     * legacy `object.write` these creates fall back to otherwise, which
     * grants every kind including products.
     */
    private const array KIND_PERMISSIONS = [
        'product' => ['products.add'],
        'category' => ['categories.add_edit'],
        // Both multimedia write grants create; `_own` vs `_any` separates
        // whose existing media may be edited, not who may add new media.
        'asset' => ['multimedia.add_edit_own', 'multimedia.add_edit_any'],
        'custom' => ['object.add'],
    ];

    public function __construct(
        private readonly PermissionResolverInterface $resolver,
        private readonly ObjectTypeSummaryPort $objectTypes,
        private readonly RequestStack $requests,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute && self::SUBJECT === $subject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $kind = $this->submittedKind();
        if (null === $kind) {
            return false;
        }

        $permissions = $this->resolver->resolve($user);
        foreach (self::KIND_PERMISSIONS[$kind] ?? [] as $code) {
            if ($permissions->has($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The kind of the object this request is about to create, or null when
     * the payload names no readable type this tenant can see.
     */
    private function submittedKind(): ?string
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        try {
            // getPayload() decodes the JSON body regardless of the
            // `application/ld+json` content type the admin sends, and
            // Request caches the raw content, so the serializer still
            // reads the same body afterwards.
            $objectTypeId = $request->getPayload()->get('objectTypeId');
        } catch (JsonException) {
            // A malformed body is the processor's 400 to raise, not a 500
            // from the security layer.
            return null;
        }

        if (!\is_string($objectTypeId) || !Uuid::isValid($objectTypeId)) {
            return null;
        }

        return $this->objectTypes->byId(Uuid::fromString($objectTypeId))?->kind->value;
    }
}
