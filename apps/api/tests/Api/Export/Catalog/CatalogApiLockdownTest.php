<?php

declare(strict_types=1);

namespace App\Tests\Api\Export\Catalog;

use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * CPDF-P4-02 — RBAC lockdown of the catalog PDF surface. Every catalog
 * endpoint is gated (RequiresPermissionAnnotationRule enforces the attribute at
 * static-analysis time; this test proves the runtime effect): an unauthenticated
 * request to any catalog API route is refused with 401 — the SOLE exception is
 * the public pull URL, whose 192-bit token is the credential (an unknown token
 * yields a 404, never a 401). This guards against a future catalog endpoint
 * accidentally shipping public.
 */
final class CatalogApiLockdownTest extends CatalogApiTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protectedRoutes(): iterable
    {
        $id = Uuid::v7()->toRfc4122();

        yield 'list' => ['GET', '/api/catalogs'];
        yield 'create' => ['POST', '/api/catalogs'];
        yield 'get' => ['GET', '/api/catalogs/'.$id];
        yield 'patch' => ['PATCH', '/api/catalogs/'.$id];
        yield 'delete' => ['DELETE', '/api/catalogs/'.$id];
        yield 'generate' => ['POST', '/api/catalogs/'.$id.'/generate'];
        yield 'bulk-generate' => ['POST', '/api/catalogs/bulk-generate'];
        yield 'preview-draft' => ['POST', '/api/catalogs/preview'];
        yield 'preview-saved' => ['GET', '/api/catalogs/'.$id.'/preview'];
        yield 'token-mint' => ['POST', '/api/catalogs/'.$id.'/token'];
        yield 'token-revoke' => ['DELETE', '/api/catalogs/'.$id.'/token'];
        yield 'run-history' => ['GET', '/api/catalogs/'.$id.'/runs'];
        yield 'runs-list' => ['GET', '/api/catalog-runs'];
        yield 'runs-kpi' => ['GET', '/api/catalog-runs/kpi'];
        yield 'run-cancel' => ['POST', '/api/catalog-runs/'.$id.'/cancel'];
    }

    #[Test]
    #[DataProvider('protectedRoutes')]
    public function everyCatalogEndpointRequiresAuth(string $method, string $path): void
    {
        static::createClient()->request($method, $path);

        self::assertResponseStatusCodeSame(401, sprintf('%s %s must be gated', $method, $path));
    }

    #[Test]
    public function onlyThePublicPullIsUnauthenticated(): void
    {
        // The public pull is the single NoPermissionRequired route: no session,
        // the token is the credential — an unknown token is a flat 404, not 401.
        $tenantId = Uuid::v7()->toRfc4122();
        static::createClient()->request('GET', '/api/catalogs/pull/'.$tenantId.'/deadbeeftoken.pdf');

        self::assertResponseStatusCodeSame(404);
    }
}
