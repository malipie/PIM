<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * AGENT-P9-05 (#1992) — open-core removability as a fast local test
 * (the CI `agent-removability` job is the full gate: it deletes the
 * module and compiles the core; this mirrors its cheapest, most
 * valuable assertion so a leak is caught in the unit lane too).
 *
 * The contract (ADR-0024 a): the whole agent module lives under
 * src/Agent, its DI in config/services_agent.yaml + config/packages/
 * agent.yaml, its tests under the per-suite Agent dirs - and NOTHING
 * in the rest of src/tests/config references the module namespace
 * (spelling it out here would trip the CI gate's grep, which scans
 * this file too). Deleting those paths must leave a compiling,
 * agent-free core.
 */
final class AgentRemovabilityTest extends TestCase
{
    #[Test]
    public function coreDoesNotReferenceTheAgentModule(): void
    {
        $apiRoot = \dirname(__DIR__, 2);
        $removablePrefixes = [
            $apiRoot.'/src/Agent/',
            $apiRoot.'/tests/Unit/Agent/',
            $apiRoot.'/tests/Integration/Agent/',
            $apiRoot.'/tests/Api/Agent/',
            $apiRoot.'/config/services_agent.yaml',
            $apiRoot.'/config/packages/agent.yaml',
            // This guard itself names the module it protects.
            __FILE__,
        ];

        $offenders = [];
        foreach (['src', 'tests', 'config'] as $dir) {
            foreach ($this->phpAndYamlFiles($apiRoot.'/'.$dir) as $file) {
                if ($this->isRemovable($file, $removablePrefixes)) {
                    continue;
                }
                $contents = file_get_contents($file);
                if (false !== $contents && str_contains($contents, 'App\\Agent')) {
                    $offenders[] = substr($file, \strlen($apiRoot) + 1);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Core files reference App\\Agent - the module is no longer wholesale-removable (ADR-0024):\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * @param list<string> $removablePrefixes
     */
    private function isRemovable(string $file, array $removablePrefixes): bool
    {
        foreach ($removablePrefixes as $prefix) {
            if (str_starts_with($file, $prefix) || $file === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<string>
     */
    private function phpAndYamlFiles(string $root): iterable
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);
            $ext = $file->getExtension();
            if ('php' === $ext || 'yaml' === $ext || 'yml' === $ext) {
                yield $file->getPathname();
            }
        }
    }
}
