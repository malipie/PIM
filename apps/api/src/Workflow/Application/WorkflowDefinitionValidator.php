<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use Doctrine\DBAL\Connection;

/**
 * WFL-P5-01 (#2431) — write-edge validation of a tenant workflow
 * definition. Every rule returns a field-scoped message so the builder
 * UI (WFL-P5-03) can render errors inline; an empty list = valid.
 *
 * Rules: non-empty unique places incl. the initial `draft`; transitions
 * reference existing places with unique names; every place is reachable
 * from `draft`; referenced permission codes exist; a place cannot be
 * REMOVED while tenant objects still sit in it (checked against the
 * previous shape — the machine must never strand a marking).
 */
final readonly class WorkflowDefinitionValidator
{
    public const string INITIAL_PLACE = 'draft';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<array<string, mixed>> $places
     * @param list<array<string, mixed>> $transitions
     * @param list<string>               $previousPlaceNames places of the stored shape (empty for a new definition)
     *
     * @return list<array{field: string, message: string}>
     */
    public function validate(array $places, array $transitions, array $previousPlaceNames = []): array
    {
        $errors = [];

        $placeNames = [];
        foreach ($places as $index => $place) {
            $name = $place['name'] ?? null;
            if (!\is_string($name) || 1 !== \preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name)) {
                $errors[] = ['field' => "places[$index].name", 'message' => 'Place name must be snake_case (max 32 chars).'];
                continue;
            }
            if (\in_array($name, $placeNames, true)) {
                $errors[] = ['field' => "places[$index].name", 'message' => \sprintf('Duplicate place "%s".', $name)];
                continue;
            }
            $placeNames[] = $name;
        }

        if (!\in_array(self::INITIAL_PLACE, $placeNames, true)) {
            $errors[] = ['field' => 'places', 'message' => \sprintf('The initial place "%s" is required.', self::INITIAL_PLACE)];
        }

        $transitionNames = [];
        $edges = [];
        foreach ($transitions as $index => $transition) {
            $name = $transition['name'] ?? null;
            if (!\is_string($name) || 1 !== \preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                $errors[] = ['field' => "transitions[$index].name", 'message' => 'Transition name must be snake_case (max 64 chars).'];
                continue;
            }
            if (\in_array($name, $transitionNames, true)) {
                $errors[] = ['field' => "transitions[$index].name", 'message' => \sprintf('Duplicate transition "%s".', $name)];
                continue;
            }
            $transitionNames[] = $name;

            $froms = $transition['from'] ?? null;
            $froms = \is_string($froms) ? [$froms] : (\is_array($froms) ? $froms : []);
            if ([] === $froms) {
                $errors[] = ['field' => "transitions[$index].from", 'message' => 'from is required.'];
            }
            foreach ($froms as $from) {
                if (!\is_string($from) || !\in_array($from, $placeNames, true)) {
                    $errors[] = ['field' => "transitions[$index].from", 'message' => \sprintf('Unknown place "%s".', \is_string($from) ? $from : '?')];
                }
            }

            $to = $transition['to'] ?? null;
            if (!\is_string($to) || !\in_array($to, $placeNames, true)) {
                $errors[] = ['field' => "transitions[$index].to", 'message' => \sprintf('Unknown place "%s".', \is_string($to) ? $to : '?')];
            } else {
                foreach ($froms as $from) {
                    if (\is_string($from)) {
                        $edges[$from][] = $to;
                    }
                }
            }

            $permission = $transition['permission'] ?? null;
            if (null !== $permission) {
                if (!\is_string($permission)) {
                    $errors[] = ['field' => "transitions[$index].permission", 'message' => 'permission must be a code string.'];
                } elseif (!$this->permissionExists($permission)) {
                    $errors[] = ['field' => "transitions[$index].permission", 'message' => \sprintf('Unknown permission code "%s".', $permission)];
                }
            }

            $gate = $transition['completeness_gate'] ?? null;
            if (null !== $gate) {
                $minPct = \is_array($gate) ? ($gate['min_completeness_pct'] ?? null) : null;
                if (!\is_int($minPct) || $minPct < 0 || $minPct > 100) {
                    $errors[] = ['field' => "transitions[$index].completeness_gate", 'message' => 'min_completeness_pct must be an integer 0-100.'];
                }
            }
        }

        // Reachability from the initial place (BFS over the edge map).
        if (\in_array(self::INITIAL_PLACE, $placeNames, true)) {
            $reachable = [self::INITIAL_PLACE => true];
            $queue = [self::INITIAL_PLACE];
            while ([] !== $queue) {
                $current = \array_shift($queue);
                foreach ($edges[$current] ?? [] as $next) {
                    if (!isset($reachable[$next])) {
                        $reachable[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
            foreach ($placeNames as $place) {
                if (!isset($reachable[$place])) {
                    $errors[] = ['field' => 'places', 'message' => \sprintf('Place "%s" is unreachable from "%s".', $place, self::INITIAL_PLACE)];
                }
            }
        }

        // Removing a place with live markings would strand objects.
        $removed = \array_diff($previousPlaceNames, $placeNames);
        foreach ($removed as $place) {
            $raw = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM objects WHERE status = :status',
                ['status' => $place],
            );
            $count = \is_numeric($raw) ? (int) $raw : 0;
            if ($count > 0) {
                $errors[] = ['field' => 'places', 'message' => \sprintf('Cannot remove place "%s" — %d object(s) still carry it.', $place, $count)];
            }
        }

        return $errors;
    }

    private function permissionExists(string $code): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM permissions WHERE code = :code',
            ['code' => $code],
        );
    }
}
