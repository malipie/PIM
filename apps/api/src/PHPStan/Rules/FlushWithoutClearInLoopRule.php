<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * AUD-011 (W1-8) — enforces the NON-NEGOTIABLE FrankenPHP worker-mode guard-rail
 * from `Project Plan/01-architektura-pim.md` §3.10 pkt 5 / CLAUDE.md §3.10:
 *
 *   "CI gate: a custom PHPStan rule rejecting Messenger handlers that flush
 *    inside a loop without clear() — automated detection of the pattern."
 *
 * Why static enforcement: in FrankenPHP worker mode the PHP process lives
 * between requests, so Doctrine's identity map keeps every persisted entity
 * across `flush()` calls. A Messenger handler that flushes inside a batch loop
 * WITHOUT an `EntityManager::clear()` accumulates the whole working set in
 * memory and drives a 50k-SKU import into OOM (R-25 — Krytyczny). The reference
 * pattern is {@see \App\Shared\Application\AbstractBatchHandler::flushAndClear()}
 * (flush + clear); this rule guarantees a handler that does not inherit it still
 * pairs every in-loop flush with a clear.
 *
 * Scope (faithful to §3.10 wording — "handlery Messenger"):
 *   The rule fires ONLY on Symfony Messenger handlers — classes carrying the
 *   CLASS-level `#[AsMessageHandler]` attribute (the universal marker across the
 *   codebase: all 40 handlers use it at class level; Symfony 7.x dropped the
 *   `MessageHandlerInterface` marker interface). Method-level `#[AsMessageHandler]`
 *   (multi-message handler classes) is not used anywhere today; add a method-attr
 *   scan here if that pattern is introduced. These are the long-running batch
 *   consumers the guard-rail targets. Synchronous
 *   request-scoped services that flush inside a small cardinality-bounded loop
 *   (e.g. replacing a handful of relations inside one transaction) are out of
 *   scope by design — clearing the unit of work mid-request there would detach
 *   entities the caller still holds.
 *
 * Exemption:
 *   Classes extending {@see \App\Shared\Application\AbstractBatchHandler} are
 *   skipped — the base class funnels batch flushes through `flushAndClear()`,
 *   which already guarantees the clear. §3.10 pkt 1 names inheritance of that
 *   base class as the sanctioned pattern.
 *
 * False-positive avoidance (verified against the whole codebase, zero baseline):
 *   - flush AFTER a loop (accumulate-then-flush-once) is NOT flagged — the flush
 *     has no enclosing loop node.
 *   - flush INSIDE a loop whose subtree also contains a `clear()` is NOT flagged.
 *   - nested loops: a flush is safe if ANY enclosing loop (innermost OR outer)
 *     contains a `clear()`, so a `clear()` placed after an inner loop but still
 *     inside the outer loop discharges the inner flush. Each offending flush is
 *     reported exactly once, on its own line.
 *
 * @implements Rule<Class_>
 */
final class FlushWithoutClearInLoopRule implements Rule
{
    private const string ABSTRACT_BATCH_HANDLER = 'App\\Shared\\Application\\AbstractBatchHandler';

    private const string AS_MESSAGE_HANDLER = 'Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // $node is a Class_ per @implements Rule<Class_> / getNodeType().
        if (!$this->isMessengerHandler($node)) {
            return [];
        }

        if ($this->extendsAbstractBatchHandler($node, $scope)) {
            return [];
        }

        $nodeFinder = new NodeFinder();

        /** @var list<For_|Foreach_|While_|Do_> $loops */
        $loops = $nodeFinder->find($node, static fn (Node $n): bool => $n instanceof For_
            || $n instanceof Foreach_
            || $n instanceof While_
            || $n instanceof Do_);

        if ([] === $loops) {
            return [];
        }

        $errors = [];

        // Per-flush model: a flush() that sits inside at least one loop is safe
        // iff SOME enclosing loop's subtree also contains a clear(). A clear() in
        // an outer loop discharges the obligation (the identity map is detached
        // each outer iteration), so nested-loop-with-clear-after-inner is OK. A
        // flush AFTER every loop has no enclosing loop and is never reported.
        foreach ($this->findCalls($nodeFinder, $node, 'flush') as $flush) {
            $enclosingLoops = $this->enclosingLoops($flush, $loops);
            if ([] === $enclosingLoops) {
                continue; // flush is not inside any loop — accumulate-then-flush
            }

            foreach ($enclosingLoops as $loop) {
                if ([] !== $this->findCalls($nodeFinder, $loop, 'clear')) {
                    continue 2; // a clear in an enclosing loop discharges it
                }
            }

            $errors[] = RuleErrorBuilder::message(
                'EntityManager::flush() inside a loop in a Messenger handler is not paired with a clear() '
                .'in the same loop. In FrankenPHP worker mode the Doctrine identity map accumulates across '
                .'flushes and a batch loop without clear() drives the worker into OOM (architektura §3.10, R-25). '
                .'Extend App\\Shared\\Application\\AbstractBatchHandler and flush via flushAndClear(), '
                .'or call $entityManager->clear() after flush() inside the loop.',
            )
                ->line($flush->getStartLine())
                ->identifier('pim.flushWithoutClearInLoop')
                ->build();
        }

        return $errors;
    }

    private function isMessengerHandler(Class_ $node): bool
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->matchesFqcn($attribute->name->toString(), self::AS_MESSAGE_HANDLER)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extendsAbstractBatchHandler(Class_ $node, Scope $scope): bool
    {
        // AST path (primary, robust). In a PHPStan rule the parser has already
        // resolved class names against the file's `use` imports, so the parent
        // name node carries the FQCN directly — no class-hierarchy lookup
        // needed. Covers the direct-extends case (every batch handler in the
        // codebase extends AbstractBatchHandler directly).
        if (null !== $node->extends
            && ltrim($node->extends->toString(), '\\') === self::ABSTRACT_BATCH_HANDLER) {
            return true;
        }

        // Reflection path (covers a transitive subclass, if one is ever added).
        // getParentClassesNames() walks the whole ancestor chain.
        $reflection = $scope->getClassReflection();
        if (null !== $reflection) {
            foreach ($reflection->getParentClassesNames() as $parent) {
                if (ltrim($parent, '\\') === self::ABSTRACT_BATCH_HANDLER) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find `$x->{$method}()` calls inside the given subtree.
     *
     * @return list<MethodCall>
     */
    private function findCalls(NodeFinder $nodeFinder, Node $subtree, string $method): array
    {
        /** @var list<MethodCall> $calls */
        $calls = $nodeFinder->find($subtree, static function (Node $n) use ($method): bool {
            return $n instanceof MethodCall
                && $n->name instanceof Node\Identifier
                && $n->name->toString() === $method;
        });

        return $calls;
    }

    /**
     * Every loop (from $allLoops) whose token span contains $flush — i.e. all
     * loops enclosing the flush, innermost and outermost alike.
     *
     * @param list<For_|Foreach_|While_|Do_> $allLoops
     *
     * @return list<For_|Foreach_|While_|Do_>
     */
    private function enclosingLoops(MethodCall $flush, array $allLoops): array
    {
        $flushStart = $flush->getStartTokenPos();
        $flushEnd = $flush->getEndTokenPos();

        $enclosing = [];
        foreach ($allLoops as $loop) {
            if ($loop->getStartTokenPos() <= $flushStart && $loop->getEndTokenPos() >= $flushEnd) {
                $enclosing[] = $loop;
            }
        }

        return $enclosing;
    }

    private function matchesFqcn(string $name, string $fqcn): bool
    {
        $name = ltrim($name, '\\');
        if ($name === $fqcn) {
            return true;
        }

        // Short-name match (the import resolves to the FQCN). PHPStan resolves
        // attribute names against the file's `use` statements, but guard the
        // short form too for robustness.
        $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

        return $name === $short || str_ends_with($name, '\\'.$short);
    }
}
