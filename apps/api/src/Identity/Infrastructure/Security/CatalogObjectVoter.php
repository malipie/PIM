<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

/**
 * RBAC voter for the generic `CatalogObject` aggregate (per ADR-009).
 *
 * The same voter handles every kind (`product`, `category`, `asset`,
 * `custom`) — the kind-aware sugar paths under `/api/products`,
 * `/api/categories`, `/api/assets` all instantiate the same `CatalogObject`
 * class, so a single resource string `'object'` covers them. Per-kind
 * differentiation lives at the API surface (operation `extraProperties.kind`)
 * and the serializer context, not at the authorization layer.
 *
 * The subject class is referenced as a fully-qualified string rather than
 * an `use` import to keep `Identity_Internals` independent of
 * `Catalog_Internals` per Deptrac (ADR-0013) — voters know about
 * permissions, not domain shape.
 */
final class CatalogObjectVoter extends AbstractRbacVoter
{
    /**
     * @return array<string, string>
     */
    protected function attributeMap(): array
    {
        return [
            'READ' => 'read',
            'CREATE' => 'write',
            // WFL-P6-02 (#2435) — the products sugar path asks for
            // CREATE_PRODUCT so ProductVoter can grant products.add
            // without leaking onto the other kinds' POSTs. Legacy
            // object.write principals keep creating products through
            // this alias (affirmative strategy: either voter grants).
            'CREATE_PRODUCT' => 'write',
            'READ_PRODUCT' => 'read',
            // #2848 — the poly-kind /api/objects collection moved off the
            // shared 'READ' attribute so Prd\ObjectCollectionVoter can grant
            // per-kind when the query is scoped by ?objectType=. Legacy
            // object.read principals keep browsing the unscoped collection
            // through this alias.
            'READ_OBJECT_COLLECTION' => 'read',
            'UPDATE' => 'write',
            'WRITE' => 'write',
            'DELETE' => 'delete',
        ];
    }

    protected function resource(): string
    {
        return 'object';
    }

    protected function subjectClass(): string
    {
        return 'App\\Catalog\\Domain\\Entity\\CatalogObject';
    }
}
