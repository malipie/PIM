<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan;

use App\PHPStan\Rules\LegacyPermissionCodeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2881 punkt C — proof that the gate closes on the shape that produced
 * five rounds of the same bug, and stays open for the shapes that are fine.
 *
 * The rule's value is entirely in the second half: a gate that also fires
 * on correct code gets suppressed, and a suppressed gate stops guarding.
 *
 * @extends RuleTestCase<LegacyPermissionCodeRule>
 */
#[Group('architecture')]
final class LegacyPermissionCodeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new LegacyPermissionCodeRule();
    }

    #[Test]
    public function flagsALegacyCodeWithNoPrdAlternative(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/LegacyPermissionEndpoints.php'],
            [
                [$this->messageFor('legacyOnly', 'channel.read'), 19],
                [$this->messageFor('legacyOnlyAnyOf', 'channel.read'), 39],
            ],
        );
    }

    private function messageFor(string $method, string $code): string
    {
        [$module, $action] = explode('.', $code);

        return \sprintf(
            'Endpoint %s::%s() is gated by the legacy permission code "%s" alone. '
            .'Roles created through the panel carry only PRD §3.2 codes, so this endpoint is '
            .'closed to every one of them. Add the PRD equivalent via '
            .'anyOf: [\'%s.%s\', \'<prd.code>\'] — see #2881 for the mapping table.',
            Fixtures\LegacyPermissionEndpoints::class,
            $method,
            $code,
            $module,
            $action,
        );
    }
}
