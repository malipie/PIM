<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use App\Identity\Infrastructure\Doctrine\RlsContextListener;
use App\Shared\Infrastructure\Doctrine\RlsTenantGuard;
use App\Shared\Infrastructure\Maintenance\TenantPurger;
use App\Shared\Infrastructure\Messenger\TenantRlsGucMiddleware;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * #2978 — the RLS session variables are set in exactly three classes, and
 * nowhere else.
 *
 * `app.current_tenant` is the tenant marker every RLS policy reads;
 * `app.is_super_admin` is the break-glass bypass. Setting either one is
 * setting the tenant isolation boundary, so the code doing it is
 * security-critical and belongs in one reviewed place per entry point:
 *
 *   - {@see RlsContextListener}      — HTTP requests carrying a principal,
 *   - {@see TenantRlsGucMiddleware}  — Symfony Messenger workers,
 *   - {@see TenantScopeBinder}       — console commands and signed,
 *                                      session-less routes.
 *
 * Before #2978 five console commands and three controllers carried their own
 * copy of the statement pair. The copies drifted: none of the command copies
 * re-applied the Doctrine tenant filter, so the PHP-side filter could still
 * hold the previous tenant while the GUC named the current one — the same
 * class of divergence that produced #2975/#2976/#2977 in production. A copy
 * is also a copy of the *omission*: whoever pastes four lines pastes whatever
 * the source forgot.
 *
 * The rule flags the string literal rather than the DBAL call, so it catches
 * the statement however it is assembled (inline argument, constant,
 * concatenation into a query). `migrations/` is outside PHPStan's analysed
 * paths, so the migrations that create the policies are unaffected.
 *
 * Exempting a new class is a deliberate act: add it below with a comment
 * saying which entry point it serves and why the existing three do not cover
 * it.
 *
 * @implements Rule<String_>
 */
final class RawTenantGucRule implements Rule
{
    /**
     * Session variables that define the tenant isolation boundary.
     *
     * @var list<string>
     */
    private const array GUARDED_SETTINGS = ['app.current_tenant', 'app.is_super_admin'];

    /**
     * The classes allowed to write them. The first three are the entry-point
     * binders; the last two are narrow, deliberate exceptions.
     *
     * @var list<class-string>
     */
    private const array ALLOWED_CLASSES = [
        RlsContextListener::class,
        TenantRlsGucMiddleware::class,
        TenantScopeBinder::class,
        // #2156 — re-asserts the GUC immediately before an RLS-protected write,
        // because a FrankenPHP worker's long-lived DBAL connection can silently
        // reconnect mid-request and a session variable dies with the physical
        // connection. It re-sets the tenant already bound by an entry point
        // rather than choosing one, so it is not a second door.
        RlsTenantGuard::class,
        // Tenant offboarding hard-delete: it walks tables for a tenant that is
        // being removed, outside any request or command scope, and resets the
        // GUC in its own finally. Routing it through the binder would bind a
        // TenantContext for an entity mid-deletion.
        TenantPurger::class,
    ];

    public function getNodeType(): string
    {
        return String_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $setting = $this->guardedSettingIn($node->value);
        if (null === $setting) {
            return [];
        }

        // Only a write is a boundary change. `current_setting(...)` reads the
        // value back and is harmless — RLS policies and diagnostics do it.
        if (!str_contains($node->value, 'set_config')) {
            return [];
        }

        $class = $scope->getClassReflection();
        if (null !== $class && \in_array($class->getName(), self::ALLOWED_CLASSES, true)) {
            return [];
        }

        // The test suite asserts ON this boundary — proving that an empty GUC
        // hides rows, that a worker rebinds it, that a drifted GUC name breaks
        // login. Those tests must be able to write it directly; forcing them
        // through the binder would make them assert the binder instead of the
        // policy. Only real test cases are exempt (`App\Tests\…Test`), not the
        // fixtures under them — those exist precisely to be flagged.
        $name = $class?->getName();
        if (null !== $name && str_starts_with($name, 'App\\Tests\\') && str_ends_with($name, 'Test')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Class %s writes the RLS session variable "%s" directly. That statement IS the tenant '
                .'isolation boundary, and hand-rolled copies drift from the original — the copies this '
                .'rule replaced all forgot to re-apply the Doctrine tenant filter. Bind the tenant '
                .'through %s::bind() instead; if a genuinely new entry point needs its own binding, add '
                .'it to ALLOWED_CLASSES with a comment saying why. See #2978.',
                $class?->getName() ?? '(unknown)',
                $setting,
                TenantScopeBinder::class,
            ))
                ->identifier('tenant.rawRlsSessionVariable')
                ->build(),
        ];
    }

    private function guardedSettingIn(string $value): ?string
    {
        foreach (self::GUARDED_SETTINGS as $setting) {
            if (str_contains($value, $setting)) {
                return $setting;
            }
        }

        return null;
    }
}
