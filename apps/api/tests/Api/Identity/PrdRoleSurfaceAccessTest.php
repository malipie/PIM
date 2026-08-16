<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\Permission;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * #2881 punkt B — one control per surface that gained a PRD alternative.
 *
 * The ticket's whole risk is that "accept the second catalogue too"
 * quietly becomes "accept anything". Eighty-seven endpoints moved from a
 * legacy-only code to `anyOf: [legacy, …PRD]`, and the honest way to
 * show that each one is still a gate is to send a principal that holds
 * *neither* catalogue's code for that surface and watch it bounce.
 *
 * The control principal is a bespoke role carrying a single unrelated
 * permission (`workflow.view`) rather than a seeded template. Seeded
 * roles were unusable for this: every PRD template holds
 * `products.view`, which is a legitimate alternative on the schema-read
 * surfaces (#2838 — reading schema metadata follows reading the data it
 * describes), so no template can prove the boundary on those.
 *
 * The positive half — that the mapped PRD code actually opens the
 * surface — is asserted with a role built from exactly the mapped code,
 * so a passing row cannot be explained by some other grant riding along.
 *
 * Both halves assert on 403-or-not rather than on a success status: the
 * ids in the URLs are deliberately unknown, so an authorised caller
 * lands on the controller's own 404/400. That is the point — the guard
 * runs before the controller, so the distinction it makes is visible
 * without seeding a fixture per surface.
 */
final class PrdRoleSurfaceAccessTest extends CatalogApiTestCase
{
    private const string CONTROL_EMAIL = 'surface-control@demo.localhost';
    private const string GRANTED_EMAIL = 'surface-granted@demo.localhost';
    private const string UNKNOWN_ID = '019f0000-0000-7000-8000-000000000000';

    /**
     * Surface → [method, path, the PRD code #2881 mapped it to].
     *
     * One row per legacy resource that had endpoints left on a
     * legacy-only code, plus the two `integration.admin` surfaces that
     * were mapped differently from each other (feeds follow the API
     * configurator, catalogs follow exports).
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function surfaces(): iterable
    {
        yield 'channels (publications)' => ['GET', '/api/channels/'.self::UNKNOWN_ID.'/navigation-tree', 'publications.view'];
        yield 'asset folders (multimedia)' => ['GET', '/api/asset-folders', 'multimedia.view'];
        yield 'import sessions' => ['GET', '/api/import-sessions', 'imports.view_own'];
        yield 'import schedules' => ['GET', '/api/import-schedules/'.self::UNKNOWN_ID.'/runs', 'imports.view_own'];
        yield 'import profiles' => ['POST', '/api/import-profiles/'.self::UNKNOWN_ID.'/duplicate', 'imports.run'];
        yield 'import sources' => ['POST', '/api/import-sources/'.self::UNKNOWN_ID.'/test-connection', 'imports.run'];
        yield 'api profiles (configurator)' => ['GET', '/api/profiles/builder_options', 'settings.integrations.manage'];
        yield 'feeds (configurator)' => ['GET', '/api/feeds', 'settings.integrations.manage'];
        // Not `exports.run`: the catalog reads ask for `exports.view_all`, so
        // the writes do too — a write reachable by someone who cannot read the
        // surface is not a gate, it is a hole with a different name.
        yield 'pdf catalogs (exports)' => ['POST', '/api/catalogs/bulk-generate', 'exports.view_all'];
        yield 'backups (tenant admin)' => ['GET', '/api/backups/'.self::UNKNOWN_ID, 'settings.tenant.manage'];
        yield 'attribute options (schema)' => ['GET', '/api/attributes/some-code/options', 'modeling.view'];
        yield 'attribute groups (schema)' => ['GET', '/api/attribute_groups/'.self::UNKNOWN_ID.'/attributes', 'modeling.view'];
        yield 'object form schema' => ['GET', '/api/objects/'.self::UNKNOWN_ID.'/form-schema', 'modeling.view'];
        yield 'category search' => ['GET', '/api/search/categories', 'categories.view'];

        // #2881 — the API Platform half of the same sweep. These gate
        // through `security="is_granted(...)"` on the resource rather than
        // #[RequiresPermission], which is exactly why the first inventory
        // walked past them: eleven resources answered only to legacy codes
        // and returned 403 to every panel-created role, whatever it held.
        yield 'channels (resource)' => ['GET', '/api/channels', 'publications.view'];
        yield 'import profiles (resource)' => ['GET', '/api/import-profiles', 'imports.view_own'];
        yield 'import schedules (resource)' => ['GET', '/api/import-schedules', 'imports.view_own'];
        yield 'import sources (resource)' => ['GET', '/api/import-sources', 'imports.view_own'];
        yield 'connections (resource)' => ['GET', '/api/connections', 'settings.integrations.manage'];
        yield 'remote endpoints (resource)' => ['GET', '/api/remote_endpoints', 'settings.integrations.manage'];
        yield 'field mappings (resource)' => ['GET', '/api/field_mappings', 'settings.integrations.manage'];
        yield 'sync bindings (resource)' => ['GET', '/api/sync_bindings', 'settings.integrations.manage'];
        yield 'sync runs (resource)' => ['GET', '/api/sync_runs', 'settings.integrations.manage'];
        yield 'webhook deliveries (resource)' => ['GET', '/api/webhook_deliveries', 'settings.integrations.manage'];
        yield 'api keys (resource)' => ['GET', '/api/api_keys', 'settings.integrations.manage'];
        yield 'api profiles (resource)' => ['GET', '/api/api_profiles', 'settings.integrations.manage'];
    }

    /**
     * Reading a surface is not permission to change it. `publications.view`
     * opens the channel list; creating a channel needs the publish grant,
     * and without that separation the table above would have quietly turned
     * every viewer into an editor of eleven resources.
     */
    #[Test]
    public function readingAResourceDoesNotGrantWritingIt(): void
    {
        $this->givenUserWithPermissions('channels-reader@demo.localhost', 'control_channels_read', ['publications.view']);

        $client = $this->authenticatedClient('channels-reader@demo.localhost');
        $client->request('GET', '/api/channels');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/channels', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode(['code' => 'shall-not-pass', 'name' => 'Nope'], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403, 'creating a channel needs publications.publish_unpublish');
    }

    /**
     * The half that matters most: holding neither catalogue's code for a
     * surface still means 403.
     */
    #[Test]
    #[DataProvider('surfaces')]
    public function aRoleWithoutTheCodeIsRefused(string $method, string $path, string $code): void
    {
        $this->givenUserWithPermissions(self::CONTROL_EMAIL, 'control_only_workflow', ['workflow.view']);

        $this->authenticatedClient(self::CONTROL_EMAIL)->request($method, $path, [
            'headers' => ['content-type' => 'application/json'],
            'body' => '{}',
        ]);

        self::assertResponseStatusCodeSame(403, \sprintf('%s %s must stay closed without %s', $method, $path, $code));
    }

    /**
     * The other half: the mapped code, and nothing else, opens it.
     */
    #[Test]
    #[DataProvider('surfaces')]
    public function theMappedPrdCodeOpensTheSurface(string $method, string $path, string $code): void
    {
        $this->givenUserWithPermissions(self::GRANTED_EMAIL, 'granted_'.str_replace('.', '_', $code), [$code]);

        $response = $this->authenticatedClient(self::GRANTED_EMAIL)->request($method, $path, [
            'headers' => ['content-type' => 'application/json'],
            'body' => '{}',
        ]);

        self::assertNotSame(
            403,
            $response->getStatusCode(),
            \sprintf('%s %s must pass the guard for a holder of %s', $method, $path, $code),
        );
    }

    /**
     * `imports.view_own` and `imports.view_all` are two permissions, so
     * the sessions list must not serve them alike. Anything less and the
     * "accept both" mapping silently promotes every importer to seeing
     * the whole tenant's history.
     */
    #[Test]
    public function importSessionsListNarrowsToOwnUnlessViewAllIsHeld(): void
    {
        $this->givenUserWithPermissions('imports-own@demo.localhost', 'control_imports_own', ['imports.view_own']);
        $this->givenUserWithPermissions('imports-all@demo.localhost', 'control_imports_all', ['imports.view_own', 'imports.view_all']);

        $own = $this->authenticatedClient('imports-own@demo.localhost')
            ->request('GET', '/api/import-sessions')->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(0, $own['totalItems'] ?? -1, 'a holder of view_own sees only sessions they started');

        $all = $this->authenticatedClient('imports-all@demo.localhost')
            ->request('GET', '/api/import-sessions')->toArray();
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('totalItems', $all, 'view_all lifts the owner filter rather than changing the shape');
    }

    /**
     * The bulk endpoint takes the per-kind delete codes as alternatives,
     * which is only safe because it re-checks every object individually.
     * This is the assertion that keeps it safe: a caller holding
     * `products.delete` gets through the endpoint gate and is then
     * refused on a category row.
     */
    #[Test]
    public function bulkDeleteStillChecksEachObjectsKind(): void
    {
        $this->givenUserWithPermissions('bulk-products@demo.localhost', 'control_bulk_products', ['products.delete']);

        $categoryId = $this->givenCategory();

        $this->authenticatedClient('bulk-products@demo.localhost')->request('POST', '/api/objects/bulk', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['action' => 'delete', 'object_ids' => [$categoryId]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(403, 'products.delete must not reach a category through the bulk endpoint');
    }

    private function givenCategory(): string
    {
        $created = $this->authenticatedClient()->request('POST', '/api/categories', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'BULK-GUARD-CAT',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Category),
                'categoryTargetObjectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);

        $id = $created->toArray()['id'];
        \assert(\is_string($id));

        return $id;
    }

    /**
     * A bespoke role holding exactly the listed codes — no template, so
     * nothing rides along that could explain a pass.
     *
     * @param list<string> $codes
     */
    private function givenUserWithPermissions(string $email, string $roleCode, array $codes): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $role = new Role($roleCode, $roleCode, $tenant);
        foreach ($codes as $code) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['code' => $code]);
            \assert($permission instanceof Permission, \sprintf('permission "%s" is not seeded', $code));
            $role->getPermissions()->add($permission);
        }
        $em->persist($role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        // ADR-0034 — addRole() is the single write path for assignments.
        $user->addRole($role);
        $em->persist($user);
        $em->flush();
        $em->clear();
    }
}
