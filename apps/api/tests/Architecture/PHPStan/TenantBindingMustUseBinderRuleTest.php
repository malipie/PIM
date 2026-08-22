<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan;

use App\PHPStan\Rules\TenantBindingMustUseBinderRule;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2978 — proof that the gate closes on the shape that broke twelve console
 * commands, and stays open for the shapes that are fine.
 *
 * The second half carries the weight: a gate that also fires on correct code
 * gets suppressed, and a suppressed gate stops guarding. A bare
 * `TenantContext::set()` outside a command is correct — the entry point has
 * already bound the GUC — and reading the context is not binding it.
 *
 * @extends RuleTestCase<TenantBindingMustUseBinderRule>
 */
#[Group('architecture')]
final class TenantBindingMustUseBinderRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new TenantBindingMustUseBinderRule();
    }

    #[Test]
    public function flagsACommandBindingTheContextWithoutTheBinder(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/BadCommandBindingContextDirectly.php',
                __DIR__.'/Fixtures/BadCommandClearingContextDirectly.php',
                __DIR__.'/Fixtures/GoodCommandUsingBinder.php',
                __DIR__.'/Fixtures/GoodCommandReadingContext.php',
                __DIR__.'/Fixtures/GoodServiceBindingContextDirectly.php',
            ],
            [
                [$this->messageFor(Fixtures\BadCommandBindingContextDirectly::class, 'set', 'bind'), 30],
                [$this->messageFor(Fixtures\BadCommandClearingContextDirectly::class, 'clear', 'release'), 27],
            ],
        );
    }

    private function messageFor(string $class, string $method, string $replacement): string
    {
        return \sprintf(
            'Console command %s binds the tenant with TenantContext::%s() alone. A command '
            .'establishes no tenant automatically, so the Postgres GUC "app.current_tenant" stays '
            .'empty and every RLS-protected read returns zero rows (writes are rejected outright). '
            .'Use %s::%s() instead — it binds TenantContext, the Doctrine filter and the GUC '
            .'together. See #2978.',
            $class,
            $method,
            TenantScopeBinder::class,
            $replacement,
        );
    }
}
