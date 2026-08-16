<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Catalog\Contracts\Query\ObjectTypeSummaryPort;
use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * #2848 — the read gate for the poly-kind `GET /api/objects` collection.
 *
 * Every kind's list shares one endpoint and one subject (the bare
 * `CatalogObject` class string), which is why {@see \App\Identity\Infrastructure\Security\ProductVoter}
 * deliberately abstains from class-string `READ`: with no instance there is
 * no kind, and granting `products.view` there would hand the caller
 * categories and assets in the same response.
 *
 * The universal list view never asks that broad question, though. It always
 * scopes the call — `/api/objects?objectType=<uuid>` — and
 * {@see \App\Catalog\Infrastructure\ApiPlatform\Filter\ObjectTypeFilter}
 * turns that parameter into `IDENTITY(o.objectType) = :id`. The rows coming
 * back are therefore exactly one ObjectType, whose kind we can resolve and
 * authorise against. That is the whole idea here: authorise the kind the
 * query is actually narrowed to, not the widest kind it could have returned.
 *
 * No `objectType` parameter means the caller really is asking for every kind
 * at once, and that keeps requiring the broad legacy `object.read` — granted
 * by {@see \App\Identity\Infrastructure\Security\CatalogObjectVoter} through
 * the same attribute (affirmative strategy), so pre-PRD principals and the
 * cross-kind pickers that walk the unscoped collection are unaffected.
 *
 * Custom kinds map to the generic ULV-04a verb `object.view` (#985). #2848
 * originally left them out, reasoning that `kind=custom` is shared by every
 * tenant-defined ObjectType so a single PRD code cannot express "may list
 * this one" — true, but the fallback that reasoning left them on is the
 * legacy `object.read`, which is *wider* still: it opens products,
 * categories and multimedia through the same attribute. #2881 corrects it.
 * Per-type scoping remains the deferred `object_type_scope` work described
 * on {@see \App\Identity\Infrastructure\Security\ObjectScopedVoter}.
 *
 * @extends Voter<string, string>
 */
final class ObjectCollectionVoter extends Voter
{
    private const string ATTRIBUTE = 'READ_OBJECT_COLLECTION';
    private const string SUBJECT = 'App\\Catalog\\Domain\\Entity\\CatalogObject';

    /**
     * Kind → the PRD §3.2 code that lets a role browse that kind's list.
     *
     * Keyed by the kind's wire value rather than `ObjectKind` cases on
     * purpose: Deptrac keeps `Identity_Internals` out of Catalog's Domain
     * (ADR-0013), and the enum lives there. The values are part of the
     * public API contract (`?kind=product` is documented in OpenAPI), so
     * they are as stable as the enum itself.
     */
    private const array KIND_PERMISSIONS = [
        'product' => 'products.view',
        'category' => 'categories.view',
        'asset' => 'multimedia.view',
        // #2881 — the generic ULV-04a verb (#985) for tenant-defined
        // types. See the note on `Custom kinds` above, which this entry
        // corrects rather than contradicts.
        'custom' => 'object.view',
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

        $kind = $this->requestedKind();
        if (null === $kind) {
            return false;
        }

        $code = self::KIND_PERMISSIONS[$kind] ?? null;
        if (null === $code) {
            return false;
        }

        return $this->resolver->resolve($user)->has($code);
    }

    /**
     * The kind the current request is scoped to, or null when the query is
     * unscoped, malformed, or names a type this tenant cannot see.
     */
    private function requestedKind(): ?string
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        $objectType = $request->query->get('objectType');
        if (!\is_string($objectType) || !Uuid::isValid($objectType)) {
            return null;
        }

        // Cross-tenant ids resolve to null through the Doctrine TenantFilter,
        // so an id borrowed from another tenant is indistinguishable from a
        // typo — both deny.
        return $this->objectTypes->byId(Uuid::fromString($objectType))?->kind->value;
    }
}
