<?php

declare(strict_types=1);

namespace App\Identity\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\OpenApi;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Identity\Contracts\Attribute\RequiresPermission;
use ReflectionClass;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * RBAC-P6-006 (#718) — surface `#[RequiresPermission]` and
 * `#[NoPermissionRequired]` metadata on every API Platform OpenAPI
 * operation as an `x-cortex-permission` extension.
 *
 * Integrators rendering the spec (Swagger UI, code generators, audit
 * tooling) can read the required permission per endpoint without
 * cross-referencing PRD §3.2 macierz uprawnień by hand. Shape:
 *
 *   "x-cortex-permission": "products.edit"           // gated
 *   "x-cortex-permission": false                      // explicit public/probe/webhook
 *
 * Endpoints whose controller method neither carries `#[RequiresPermission]`
 * nor `#[NoPermissionRequired]` are left un-annotated — the PHPStan rule
 * `RequiresPermissionAnnotationRule` (RBAC-P1-010) is the primary gate that
 * keeps the contract complete; the runtime listener (`EndpointGuardListener`)
 * is the secondary gate. Once the Phase 6 retrofit closes the baseline,
 * every `/api/*` operation will carry the extension.
 *
 * The decorator iterates the router collection once per spec export and
 * matches `(path, http_method)` against the OpenAPI paths the inner
 * factory produced. Output is deterministic — routes are sorted by name
 * before assignment so the spec diff stays minimal when permissions move.
 */
final readonly class PermissionOpenApiFactory implements OpenApiFactoryInterface
{
    /**
     * Fallback permission per API Platform resource tag → action by HTTP
     * method. API Platform–managed CRUD operations do not carry method-
     * level `#[RequiresPermission]` because they are dispatched by the
     * `api_platform.symfony.main_controller`, not a user-written class.
     * The mapping below mirrors the per-resource permission family from
     * PRD-PIM-rbac §3.2 macierz uprawnień. Operations whose tag is missing
     * fall through to `null` (no extension emitted) — manual annotation
     * via `ApiResource(extraProperties: …)` is the future migration path.
     *
     * @var array<string, array<string, string>> tag → [HTTP method] => permission code
     */
    private const array RESOURCE_DEFAULTS = [
        'ApiKey' => [
            'GET' => 'api_tokens.all.view_revoke', 'POST' => 'api_tokens.own.crud',
            'PATCH' => 'api_tokens.own.crud', 'PUT' => 'api_tokens.own.crud', 'DELETE' => 'api_tokens.all.view_revoke',
        ],
        'ApiProfile' => [
            'GET' => 'api_profile.read', 'POST' => 'api_profile.write',
            'PATCH' => 'api_profile.write', 'PUT' => 'api_profile.write', 'DELETE' => 'api_profile.delete',
        ],
        'AssetStorage' => [
            'GET' => 'asset.read', 'POST' => 'asset.write',
            'PATCH' => 'asset.write', 'PUT' => 'asset.write', 'DELETE' => 'asset.delete',
        ],
        // ADR-014 / MOD-02 (#894): Association resource removed — replaced by
        // `relation`-typed attributes (covered by the `attribute.*` codes).
        'Attribute' => [
            'GET' => 'attribute.read', 'POST' => 'modeling.attributes.add_edit',
            'PATCH' => 'modeling.attributes.add_edit', 'PUT' => 'modeling.attributes.add_edit', 'DELETE' => 'attribute.delete',
        ],
        'AttributeGroup' => [
            'GET' => 'attribute_group.read', 'POST' => 'modeling.attribute_groups.add_edit',
            'PATCH' => 'modeling.attribute_groups.add_edit', 'PUT' => 'modeling.attribute_groups.add_edit', 'DELETE' => 'attribute_group.delete',
        ],
        'CatalogObject' => [
            'GET' => 'products.view', 'POST' => 'products.add',
            'PATCH' => 'products.edit', 'PUT' => 'products.edit', 'DELETE' => 'products.delete',
        ],
        'Channel' => [
            'GET' => 'channel.read', 'POST' => 'channel.write',
            'PATCH' => 'channel.write', 'PUT' => 'channel.write', 'DELETE' => 'channel.delete',
        ],
        'Locale' => [
            // Read-only reference data for every authenticated user.
            'GET' => 'user.read',
        ],
        'ImportProfile' => [
            'GET' => 'import_profile.read', 'POST' => 'import_profile.write',
            'PATCH' => 'import_profile.write', 'PUT' => 'import_profile.write', 'DELETE' => 'import_profile.delete',
        ],
        'ImportSchedule' => [
            'GET' => 'import_schedule.read', 'POST' => 'import_schedule.write',
            'PATCH' => 'import_schedule.write', 'PUT' => 'import_schedule.write', 'DELETE' => 'import_schedule.delete',
        ],
        'ImportSource' => [
            'GET' => 'import_source.read', 'POST' => 'import_source.write',
            'PATCH' => 'import_source.write', 'PUT' => 'import_source.write', 'DELETE' => 'import_source.delete',
        ],
        'ObjectType' => [
            'GET' => 'object_type.read', 'POST' => 'modeling.object_types.add',
            'PATCH' => 'modeling.object_types.add', 'PUT' => 'modeling.object_types.add', 'DELETE' => 'object_type.delete',
        ],
        // Login Check is the JWT issuance endpoint — inherently public.
        'Login Check' => [
            'POST' => '__public__',
        ],
    ];

    public function __construct(
        private OpenApiFactoryInterface $decorated,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $permissionByPathMethod = $this->collectControllerPermissions();
        if ([] === $permissionByPathMethod) {
            return $openApi;
        }

        $paths = $openApi->getPaths();
        foreach ($paths->getPaths() as $path => $pathItem) {
            if (!$pathItem instanceof PathItem) {
                continue;
            }
            $newPathItem = $pathItem;
            $changed = false;

            foreach (self::METHODS as $method) {
                $op = $this->readOperation($pathItem, $method);
                if (!$op instanceof Operation) {
                    continue;
                }

                // 1) Method-level #[RequiresPermission] on a custom controller
                $permission = $this->resolvePermission($permissionByPathMethod, $path, $method);

                // 2) API Platform–managed operation → derive from resource tag
                if (null === $permission) {
                    $permission = $this->permissionFromResourceTag($op, $method);
                }

                if (null === $permission) {
                    continue;
                }

                $extensionValue = match ($permission) {
                    false, '__public__' => false,
                    default => $permission,
                };

                $updated = $op->withExtensionProperty('x-cortex-permission', $extensionValue);
                if (!$updated instanceof Operation) {
                    continue;
                }
                $newPathItem = $this->writeOperation($newPathItem, $method, $updated);
                $changed = true;
            }

            if ($changed) {
                $paths->addPath($path, $newPathItem);
            }
        }

        return $openApi;
    }

    /**
     * @return array<string, array<string, string|false>> indexed [path][METHOD] => permissionCode|false
     */
    private function collectControllerPermissions(): array
    {
        $byPathMethod = [];
        foreach ($this->router->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/')) {
                continue;
            }
            $controller = $route->getDefaults()['_controller'] ?? null;
            if (!\is_string($controller) || str_starts_with($controller, 'api_platform.')) {
                continue;
            }
            $parts = explode('::', $controller, 2);
            $class = $parts[0];
            $method = $parts[1] ?? '__invoke';
            $entry = $this->readPermissionFromMethod($class, $method);
            if (null === $entry) {
                continue;
            }
            $httpMethods = $route->getMethods();
            if ([] === $httpMethods) {
                $httpMethods = ['GET'];
            }
            foreach ($httpMethods as $httpMethod) {
                $byPathMethod[$path][strtoupper($httpMethod)] = $entry;
            }
        }

        return $byPathMethod;
    }

    /**
     * @return string|false|null permissionCode | false (NoPermissionRequired) | null (no attribute)
     */
    private function readPermissionFromMethod(string $class, string $method): string|false|null
    {
        if (!class_exists($class)) {
            return null;
        }
        try {
            $rc = new ReflectionClass($class);
            if (!$rc->hasMethod($method)) {
                return null;
            }
            $rm = $rc->getMethod($method);
        } catch (Throwable) {
            return null;
        }

        foreach ($rm->getAttributes(RequiresPermission::class) as $attr) {
            /** @var RequiresPermission $instance */
            $instance = $attr->newInstance();

            // #2874 — an endpoint that accepts either permission catalogue
            // must say so. Publishing only the primary code told integrators
            // that `user.admin` was the way in, while a PRD role holding
            // `settings.users.manage` gets through just as well; the spec is
            // a product surface, not a comment.
            $accepted = $instance->acceptedCodes();

            return \count($accepted) > 1 ? implode(' | ', $accepted) : $instance->permissionCode();
        }

        foreach ($rm->getAttributes(NoPermissionRequired::class) as $_) {
            return false;
        }

        return null;
    }

    /**
     * @param array<string, array<string, string|false>> $map
     */
    private function resolvePermission(array $map, string $path, string $method): string|false|null
    {
        return $map[$path][$method] ?? null;
    }

    private function readOperation(PathItem $pathItem, string $method): ?Operation
    {
        return match ($method) {
            'GET' => $pathItem->getGet(),
            'POST' => $pathItem->getPost(),
            'PUT' => $pathItem->getPut(),
            'PATCH' => $pathItem->getPatch(),
            'DELETE' => $pathItem->getDelete(),
            'HEAD' => $pathItem->getHead(),
            'OPTIONS' => $pathItem->getOptions(),
            'TRACE' => $pathItem->getTrace(),
            default => null,
        };
    }

    private function writeOperation(PathItem $pathItem, string $method, Operation $op): PathItem
    {
        return match ($method) {
            'GET' => $pathItem->withGet($op),
            'POST' => $pathItem->withPost($op),
            'PUT' => $pathItem->withPut($op),
            'PATCH' => $pathItem->withPatch($op),
            'DELETE' => $pathItem->withDelete($op),
            'HEAD' => $pathItem->withHead($op),
            'OPTIONS' => $pathItem->withOptions($op),
            'TRACE' => $pathItem->withTrace($op),
            default => $pathItem,
        };
    }

    private function permissionFromResourceTag(Operation $op, string $method): ?string
    {
        $tags = $op->getTags() ?? [];
        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                continue;
            }
            $byMethod = self::RESOURCE_DEFAULTS[$tag] ?? null;
            if (null === $byMethod) {
                continue;
            }
            $code = $byMethod[$method] ?? null;
            if (null !== $code) {
                return $code;
            }
        }

        return null;
    }

    /** @var list<string> */
    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE'];
}
