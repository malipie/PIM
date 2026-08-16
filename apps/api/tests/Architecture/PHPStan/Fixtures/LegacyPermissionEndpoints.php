<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fixtures for {@see \App\PHPStan\Rules\LegacyPermissionCodeRule} (#2881).
 *
 * Each method is one shape the rule has to judge; the assertions live in
 * LegacyPermissionCodeRuleTest and reference these line numbers.
 */
final class LegacyPermissionEndpoints
{
    /** The defect: a legacy code with no PRD alternative. */
    #[Route('/api/x', name: 'x', methods: ['GET'])]
    #[RequiresPermission(module: 'channel', action: 'read')]
    public function legacyOnly(): void
    {
    }

    /** The fix: the same legacy code with the PRD equivalent alongside. */
    #[Route('/api/y', name: 'y', methods: ['GET'])]
    #[RequiresPermission(module: 'channel', action: 'read', anyOf: [
        'channel.read',
        'publications.view',
    ])]
    public function legacyWithPrdAlternative(): void
    {
    }

    /**
     * `anyOf` that only repeats legacy codes is not an alternative — it is
     * the same closed door with two handles.
     */
    #[Route('/api/z', name: 'z', methods: ['GET'])]
    #[RequiresPermission(module: 'channel', action: 'read', anyOf: [
        'channel.read',
        'channel.write',
    ])]
    public function legacyOnlyAnyOf(): void
    {
    }

    /** A PRD code needs no alternative. */
    #[Route('/api/a', name: 'a', methods: ['GET'])]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function prdCode(): void
    {
    }

    /**
     * `object.view` is a legacy resource name carrying a PRD code (the
     * ULV-04a verbs, #985), so it is already reachable by panel roles.
     */
    #[Route('/api/b', name: 'b', methods: ['GET'])]
    #[RequiresPermission(module: 'object', action: 'view')]
    public function collidingResourceWithPrdCode(): void
    {
    }
}
