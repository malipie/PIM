<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;
use App\Shared\Domain\Tenant;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * RBAC-P3 base for the PRD §3.2 permission-code-aligned voters.
 *
 * Where the legacy {@see AbstractRbacVoter} walks the user's role graph
 * for a (resource, action) pair against the seeded matrix
 * (`object.read`, `object.write`, …), this PRD base looks up flat
 * permission codes (`products.view`, `products.bulk_operations`,
 * `settings.users.manage`, …) via the cached {@see PermissionResolverInterface}.
 * Both styles coexist — concrete voters opt into whichever maps to the
 * permissions actually seeded for their resource.
 *
 * Subclasses declare:
 *   - the FQCN of the subject they support (class-level subject = the
 *     FQCN string, instance-level = an `instanceof` check),
 *   - the attribute → PRD-code map (Voter attribute `view` →
 *     `products.view`, etc.),
 *   - optionally an `acceptsSubject()` override (for kind-discriminated
 *     entities like `CatalogObject(kind=Product)`).
 *
 * Common behaviour handled here:
 *   - anonymous / non-`User` principals → deny,
 *   - permission code missing from the resolver's set → deny,
 *   - cross-tenant subject (instance-level only; class-level is gated
 *     by Doctrine TenantFilter) → deny.
 *
 * @extends Voter<string, object|string>
 */
abstract class AbstractPrdVoter extends Voter
{
    public function __construct(
        private readonly PermissionResolverInterface $resolver,
    ) {
    }

    /**
     * Voter attribute → permission code, or a list of codes any one of
     * which grants it.
     *
     * #2838 — the list form exists because reading schema metadata is
     * implied by reading the data it describes: a Catalog Manager must be
     * able to fetch the product ObjectType to render the product list,
     * without holding `modeling.view` (which would also open the whole
     * Modeling section in the sidebar).
     *
     * @return array<string, string|list<string>>
     */
    abstract protected function permissionMap(): array;

    /**
     * FQCN of the subject this voter handles. Class-level votes pass this
     * string as the subject; instance-level votes pass an object whose
     * class matches (or is a subclass of) the FQCN.
     */
    abstract protected function subjectClass(): string;

    /**
     * Concrete voters can refine the subject check — e.g. for
     * `CatalogObject` with a `kind` discriminator the Product voter
     * accepts only kind=Product instances.
     */
    protected function acceptsSubject(mixed $subject): bool
    {
        if (\is_string($subject)) {
            return $subject === $this->subjectClass();
        }

        $class = $this->subjectClass();

        return $subject instanceof $class;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\array_key_exists($attribute, $this->permissionMap())) {
            return false;
        }

        return $this->acceptsSubject($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $mapped = $this->permissionMap()[$attribute] ?? null;
        if (null === $mapped) {
            return false;
        }

        $permissions = $this->resolver->resolve($user);
        $granted = false;
        foreach ((array) $mapped as $code) {
            if ($permissions->has($code)) {
                $granted = true;
                break;
            }
        }
        if (!$granted) {
            return false;
        }

        // Class-level subject (Post / GetCollection): no tenant scope to
        // check at the voter level — Doctrine TenantFilter narrows reads
        // before they reach the voter.
        if (\is_string($subject)) {
            return true;
        }

        $subjectTenant = $this->extractTenant($subject);
        if (null !== $subjectTenant
            && $subjectTenant->getId()->toRfc4122() !== $user->getTenant()->getId()->toRfc4122()) {
            return false;
        }

        return true;
    }

    /**
     * Default reflection-friendly extractor — works for every entity that
     * exposes `getTenant(): ?Tenant`. Concrete voters override for join
     * tables / non-standard tenant access.
     */
    protected function extractTenant(object $subject): ?Tenant
    {
        if (!method_exists($subject, 'getTenant')) {
            return null;
        }

        $tenant = $subject->getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
