<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use App\Shared\Application\TenantContext;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\Console\Command\Command;

/**
 * #2978 — a console command may not bind the tenant through
 * {@see TenantContext} alone.
 *
 * "Which tenant am I working on" lives in three places at once: TenantContext
 * (PHP), the Doctrine tenant filter, and the Postgres GUC `app.current_tenant`
 * that every RLS policy reads. HTTP requests and Messenger workers bind all
 * three automatically (RlsContextListener / TenantRlsGucMiddleware). **Console
 * commands bind nothing automatically**, so a command that sets only
 * TenantContext leaves RLS without a tenant — and the app connects as
 * `pim_app` (NOBYPASSRLS) against FORCE-RLS tables, so reads return zero rows
 * and writes are rejected.
 *
 * The failure is silent in the worst way. `pim:asset:upload` at least crashed
 * with `new row violates row-level security policy`; `pim:agent:start`
 * reported "no active Anthropic BYOK key is configured" for a tenant whose key
 * was configured and enabled — the row was merely invisible. Twelve of the
 * nineteen tenant-binding commands carried this defect before #2978.
 *
 * {@see TenantScopeBinder::bind()} sets all three together. This rule makes it
 * the only way in from a command.
 *
 * Scope note: the rule fires on console commands only. Elsewhere (request
 * listeners, worker middleware, import/export handlers running under a tenant
 * the framework already bound) a bare `TenantContext::set()` is legitimate —
 * the GUC is already established by the entry point. The companion
 * {@see RawTenantGucRule} covers the other half: hand-rolled copies of the
 * `set_config` pair drifting apart from the binder.
 *
 * @implements Rule<MethodCall>
 */
final class TenantBindingMustUseBinderRule implements Rule
{
    private const array GUARDED_METHODS = ['set', 'clear'];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $method = $node->name->toString();
        if (!\in_array($method, self::GUARDED_METHODS, true)) {
            return [];
        }

        $calledOn = $scope->getType($node->var);
        if (!\in_array(TenantContext::class, $calledOn->getObjectClassNames(), true)) {
            return [];
        }

        $class = $scope->getClassReflection();
        if (null === $class || !\in_array(Command::class, $class->getParentClassesNames(), true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Console command %s binds the tenant with TenantContext::%s() alone. A command '
                .'establishes no tenant automatically, so the Postgres GUC "app.current_tenant" stays '
                .'empty and every RLS-protected read returns zero rows (writes are rejected outright). '
                .'Use %s::%s() instead — it binds TenantContext, the Doctrine filter and the GUC '
                .'together. See #2978.',
                $class->getName(),
                $method,
                TenantScopeBinder::class,
                'set' === $method ? 'bind' : 'release',
            ))
                ->identifier('tenant.bindingWithoutScopeBinder')
                ->build(),
        ];
    }
}
