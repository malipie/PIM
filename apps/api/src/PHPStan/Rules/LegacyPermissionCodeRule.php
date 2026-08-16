<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use App\Identity\Application\PrdPermissionSeeder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Rbac\RbacMatrix;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * #2881 punkt C — an endpoint may not be gated by a legacy permission
 * code alone.
 *
 * PIM carries two permission catalogues in one table: the legacy
 * `{resource}.{action}` grid from {@see RbacMatrix} (`object.write`,
 * `channel.read`, …) and the PRD §3.2 `{module}.{action}` codes from
 * {@see PrdPermissionSeeder} (`products.add`, `publications.view`, …).
 * Roles created through the panel carry **only** the PRD codes. An
 * endpoint asking for a legacy code alone is therefore closed to every
 * role a tenant can actually create — the user holds the permission, the
 * panel says so, and the server says 403.
 *
 * That defect was found and fixed five times, screen by screen (#2841,
 * #2849, #2852, #2877, #2880), because nothing stopped the next feature
 * from reintroducing it. This rule is that stop: a new
 * `#[RequiresPermission]` naming a legacy resource must list at least one
 * PRD code in `anyOf`.
 *
 * It deliberately does not check the *choice* of PRD code — that is a
 * judgement call about who should reach the surface, and no static rule
 * can make it. What it can guarantee is that the judgement was made at
 * all.
 *
 * Two resource names live in both catalogues. `object.view` / `.add` /
 * `.edit` / `.delete` / `.export` are the ULV-04a verbs (#985) and are
 * seeded as PRD codes even though `object` is also a legacy resource —
 * `object.delete` is literally one row serving both. A primary code that
 * is itself in the PRD catalogue is therefore not flagged: it is already
 * a code panel-created roles can hold.
 *
 * Removing the legacy catalogue entirely would make this rule redundant;
 * until then it is what keeps the two catalogues from drifting apart
 * again.
 *
 * @implements Rule<ClassMethod>
 */
final class LegacyPermissionCodeRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($this->requiresPermissionAttributes($node) as $attribute) {
            $module = $this->stringArgument($attribute, 'module', 0);
            $action = $this->stringArgument($attribute, 'action', 1);
            if (null === $module || null === $action) {
                // A computed argument — out of reach for static analysis
                // and vanishingly rare on a controller attribute.
                continue;
            }

            if (!\in_array($module, RbacMatrix::legacyResources(), true)) {
                continue;
            }

            // Codes carried by both catalogues (the ULV-04a `object.*`
            // verbs) are already reachable by panel-created roles.
            if (\in_array($module.'.'.$action, PrdPermissionSeeder::PRD_PERMISSION_CODES, true)) {
                continue;
            }

            if ($this->listsAPrdCode($attribute)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Endpoint %s::%s() is gated by the legacy permission code "%s.%s" alone. '
                .'Roles created through the panel carry only PRD §3.2 codes, so this endpoint is '
                .'closed to every one of them. Add the PRD equivalent via '
                .'anyOf: [\'%s.%s\', \'<prd.code>\'] — see #2881 for the mapping table.',
                $scope->getClassReflection()?->getName() ?? '(unknown)',
                $node->name->toString(),
                $module,
                $action,
                $module,
                $action,
            ))
                ->identifier('rbac.legacyPermissionCodeWithoutPrdAlternative')
                ->build();
        }

        return $errors;
    }

    /**
     * @return list<Node\Attribute>
     */
    private function requiresPermissionAttributes(ClassMethod $node): array
    {
        $found = [];
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $attribute->name->toString();
                if (RequiresPermission::class === $name || 'RequiresPermission' === $name) {
                    $found[] = $attribute;
                }
            }
        }

        return $found;
    }

    private function stringArgument(Node\Attribute $attribute, string $name, int $position): ?string
    {
        foreach ($attribute->args as $index => $arg) {
            $matchesName = null !== $arg->name && $arg->name->toString() === $name;
            $matchesPosition = null === $arg->name && $index === $position;
            if (!$matchesName && !$matchesPosition) {
                continue;
            }

            return $arg->value instanceof String_ ? $arg->value->value : null;
        }

        return null;
    }

    private function listsAPrdCode(Node\Attribute $attribute): bool
    {
        foreach ($attribute->args as $arg) {
            if (null === $arg->name || 'anyOf' !== $arg->name->toString()) {
                continue;
            }
            if (!$arg->value instanceof Array_) {
                // A constant or variable list — assume the author knew what
                // they were doing rather than block on something this rule
                // cannot read.
                return true;
            }

            foreach ($arg->value->items as $item) {
                if (!$item->value instanceof String_) {
                    continue;
                }
                if (\in_array($item->value->value, PrdPermissionSeeder::PRD_PERMISSION_CODES, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
