<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Deptrac;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

use const JSON_THROW_ON_ERROR;

/**
 * Smoke test that the Deptrac ruleset stays green inside the test suite,
 * not just in CI. The CLI binary itself does the work; we only invoke it,
 * capture the exit code, and surface the same number of violations PHPUnit
 * sees (so a `bin/phpunit --testsuite=architecture` run is enough to catch
 * a freshly-introduced cross-BC import without relying on remote CI).
 *
 * The test is tagged with the `architecture` group; running it requires
 * the binary at vendor/bin/deptrac which is `composer install`'d on every
 * CI run + every developer setup.
 */
#[Group('architecture')]
final class DeptracAnalyseTest extends TestCase
{
    private const int MAX_SKIPPED_VIOLATIONS = 309;
    private const int MAX_BASELINE_PAIRS = 175;

    #[Test]
    public function rulesetPassesAndBaselineDoesNotRegrow(): void
    {
        $process = new Process(
            command: [
                'vendor/bin/deptrac',
                'analyse',
                '--no-progress',
                '--no-cache',
                '--report-uncovered',
                '--report-skipped',
                '--fail-on-uncovered',
                '--formatter=json',
            ],
            cwd: \dirname(__DIR__, 3),
            timeout: 120,
        );
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            "Deptrac analyse failed:\n".$process->getOutput().$process->getErrorOutput(),
        );

        /** @var array{Report: array{'Violations': int, 'Skipped violations': int, 'Uncovered': int, 'Errors': int}} $result */
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $report = $result['Report'];

        self::assertSame(0, $report['Violations']);
        self::assertSame(0, $report['Uncovered']);
        self::assertSame(0, $report['Errors']);
        self::assertLessThanOrEqual(
            self::MAX_SKIPPED_VIOLATIONS,
            $report['Skipped violations'],
            'Deptrac skipped-violation budget regrew; remove the new edge instead of extending the baseline.',
        );

        /** @var array{parameters: array{skip_violations: array<string, list<string>>}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/deptrac.yaml');
        $baselinePairs = array_sum(array_map(count(...), $config['parameters']['skip_violations']));

        self::assertLessThanOrEqual(
            self::MAX_BASELINE_PAIRS,
            $baselinePairs,
            'Deptrac baseline-pair budget regrew; remove the new pair instead of extending skip_violations.',
        );
    }
}
