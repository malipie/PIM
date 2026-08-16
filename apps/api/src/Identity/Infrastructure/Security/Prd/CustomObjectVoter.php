<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Identity\Infrastructure\Security\AbstractPrdVoter;

/**
 * #2881 — the PRD voter for tenant-defined ObjectTypes (`kind=custom`,
 * ADR-009).
 *
 * The three built-in kinds each got a voter of their own — products in
 * #2416, categories and multimedia in #2852 — and `kind=custom` was left
 * out every time, so a custom module's objects fell through to the legacy
 * `object.read` / `object.write` / `object.delete` grid that no PRD role
 * holds. That is not hypothetical: the operator's second production tenant
 * has a custom "Produkt" module, and every read and write against it
 * answered 403.
 *
 * What a custom kind maps to is the generic ULV-04a verb set (#985) —
 * `object.view` / `object.edit` / `object.delete` — rather than a per-kind
 * code, because every tenant-defined type shares the single `custom`
 * discriminator and no per-kind code can name one of them. #2848 read that
 * ambiguity as a reason to leave custom kinds on the broad legacy grant;
 * this reverses that call for one concrete reason: the fallback is *wider*,
 * not narrower. Legacy `object.read` opens products, categories and
 * multimedia through the same attribute, while `object.view` opens only
 * what the ULV verbs were seeded to open.
 *
 * Per-ObjectType scoping ("may view Cars but not Bikes") is still the
 * deferred `object_type_scope` work described on
 * {@see \App\Identity\Infrastructure\Security\ObjectScopedVoter}; when it
 * lands, this voter follows it without changing shape.
 *
 * Class-string subjects are shared by every kind, so they are not this
 * voter's business: the collection is decided by
 * {@see ObjectCollectionVoter} (which resolves the kind from
 * `?objectType=`) and the create by {@see ObjectCreateVoter} (from the
 * payload's `objectTypeId`).
 *
 * Like both of those, this voter names the subject class as a string and
 * compares the kind by its wire value rather than importing
 * `CatalogObject` / `ObjectKind`. The sibling voters predate that habit
 * and carry Deptrac `skip_violations` entries for the reach; a chain of
 * `->getKind()->value` creates no static reference, so `Identity_Internals`
 * stays out of Catalog's Domain (ADR-0013) with no baseline entry at all.
 */
final class CustomObjectVoter extends AbstractPrdVoter
{
    private const string SUBJECT = 'App\\Catalog\\Domain\\Entity\\CatalogObject';
    private const string KIND = 'custom';

    /**
     * @return array<string, string|list<string>>
     */
    protected function permissionMap(): array
    {
        return [
            'READ' => 'object.view',
            'UPDATE' => 'object.edit',
            'DELETE' => 'object.delete',
        ];
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (\is_string($subject)) {
            return false;
        }

        return parent::supports($attribute, $subject);
    }

    protected function subjectClass(): string
    {
        return self::SUBJECT;
    }

    protected function acceptsSubject(mixed $subject): bool
    {
        if (!\is_object($subject) || self::SUBJECT !== $subject::class) {
            return false;
        }

        return self::KIND === $subject->getKind()->value;
    }
}
