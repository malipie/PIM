<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan;

use App\PHPStan\Rules\RawTenantGucRule;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2978 — the `set_config` pair that defines tenant isolation may only be
 * written by an entry-point binder.
 *
 * The negative cases matter as much as the positive ones: reading the value
 * back (`current_setting`) is what diagnostics and RLS policies do, and an
 * unrelated session variable is none of this rule's business. A rule that
 * fired on those would be turned off within a week.
 *
 * @extends RuleTestCase<RawTenantGucRule>
 */
#[Group('architecture')]
final class RawTenantGucRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RawTenantGucRule();
    }

    #[Test]
    public function flagsHandRolledWritesOfTheIsolationBoundary(): void
    {
        $this->analyse(
            [
                __DIR__.'/Fixtures/BadServiceWritingTenantGuc.php',
                __DIR__.'/Fixtures/BadServiceWritingSuperAdminGuc.php',
                __DIR__.'/Fixtures/GoodServiceReadingTenantGuc.php',
                __DIR__.'/Fixtures/GoodServiceWritingUnrelatedSetting.php',
            ],
            [
                [$this->messageFor(Fixtures\BadServiceWritingTenantGuc::class, 'app.current_tenant'), 25],
                [$this->messageFor(Fixtures\BadServiceWritingSuperAdminGuc::class, 'app.is_super_admin'), 22],
            ],
        );
    }

    private function messageFor(string $class, string $setting): string
    {
        return \sprintf(
            'Class %s writes the RLS session variable "%s" directly. That statement IS the tenant '
            .'isolation boundary, and hand-rolled copies drift from the original — the copies this '
            .'rule replaced all forgot to re-apply the Doctrine tenant filter. Bind the tenant '
            .'through %s::bind() instead; if a genuinely new entry point needs its own binding, add '
            .'it to ALLOWED_CLASSES with a comment saying why. See #2978.',
            $class,
            $setting,
            TenantScopeBinder::class,
        );
    }
}
