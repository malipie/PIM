<?php

declare(strict_types=1);

namespace App\Catalog\Application\Bulk;

use App\Catalog\Application\BulkContext;
use App\Catalog\Application\Lock\AttributeLockReader;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Entity\BulkLog;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * VIEW-13 (#545) — `increment_numeric` bulk action.
 *
 * Applies an arithmetic operator (+ - * / %) to a numeric attribute
 * across N products. Cmd+K killer use case (PRD §3.5): `price *= 1.10`.
 * Skips rows where the current value is not numeric (warning log).
 * Locked attributes (VIEW-33 / PRD §8.3) skip with a warning entry.
 *
 * Division-by-zero raises a per-row error log (no-op) rather than
 * aborting the batch. Shared lifecycle: {@see AbstractBulkHandler}.
 */
final class BulkIncrementNumericHandler extends AbstractBulkHandler
{
    private string $attrCode = '';
    private string $operator = '+';
    private float $operand = 0.0;

    public function __construct(
        CatalogObjectRepositoryInterface $catalogObjects,
        EntityManagerInterface $em,
        BulkContext $bulkContext,
        private readonly AttributeLockReader $lockReader,
        BulkReindexQueueInterface $reindexQueue,
    ) {
        parent::__construct($catalogObjects, $em, $bulkContext, $reindexQueue);
    }

    /**
     * @return array{success: int, skipped: int, error: int}
     */
    public function handle(BulkSession $session, string $attrCode, string $operator, float $operand): array
    {
        if (!\in_array($operator, ['+', '-', '*', '/', '%'], true)) {
            throw new BadRequestHttpException(\sprintf('Unsupported operator "%s".', $operator));
        }

        $this->attrCode = $attrCode;
        $this->operator = $operator;
        $this->operand = $operand;

        return $this->runBatch($session);
    }

    protected function processObject(CatalogObject $object, BulkSession $session, BulkCounters $counters): void
    {
        if ($this->lockReader->isLocked($object, $this->attrCode)) {
            ++$counters->skipped;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $object->getAttributesIndexed()[$this->attrCode] ?? null,
                $object->getAttributesIndexed()[$this->attrCode] ?? null,
                BulkLog::LEVEL_WARNING,
                'Attribute locked',
            ));

            return;
        }

        $indexed = $object->getAttributesIndexed();
        $slot = $indexed[$this->attrCode] ?? null;
        // attributes_indexed stores the JSONB envelope {value: X, provenance?...}
        // per docs/api/jsonb-schemas.md — the numeric lives INSIDE the slot,
        // not as a bare scalar. Reading the slot directly (as this handler
        // did) made is_numeric() false for every row → the whole batch was
        // silently skipped as "not numeric". Read the scalar from the slot.
        // #2946 — a price envelope is {amount, currency} (ADR-0019 /
        // docs/api/jsonb-schemas.md), so the number lives under `amount`, not
        // `value`. Reading only `value` made every price row "not numeric"
        // and the whole batch was skipped with a warning nobody reads — the
        // operator saw an action that ran and changed nothing.
        $isPriceEnvelope = \is_array($slot) && \array_key_exists('amount', $slot);
        $oldScalar = match (true) {
            $isPriceEnvelope => $slot['amount'],
            \is_array($slot) => $slot['value'] ?? null,
            default => $slot,
        };

        if (!is_numeric($oldScalar)) {
            ++$counters->skipped;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $slot,
                $slot,
                BulkLog::LEVEL_WARNING,
                'Value is not numeric',
            ));

            return;
        }

        $current = (float) $oldScalar;
        $newScalar = match ($this->operator) {
            '+' => $current + $this->operand,
            '-' => $current - $this->operand,
            '*' => $current * $this->operand,
            '/' => $this->safeDivide($current, $this->operand),
            '%' => $this->safeModulo($current, $this->operand),
            default => null,
        };

        if (null === $newScalar) {
            ++$counters->error;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $slot,
                null,
                BulkLog::LEVEL_ERROR,
                'Division by zero',
            ));

            return;
        }

        // Write the new scalar back INTO the envelope, preserving the rest
        // of the slot (e.g. provenance, and the price's currency). The `+`
        // union keeps the recomputed key from the left operand and every
        // other key from the existing slot.
        if ($isPriceEnvelope) {
            // Money keeps its scale: a 10% rise on 299.00 is 328.90, not
            // 328.90000000000003. Two decimals unless the stored amount
            // already carried more.
            $newSlot = ['amount' => round($newScalar, self::scaleOf($oldScalar))] + $slot;
        } else {
            $newSlot = \is_array($slot) ? ['value' => $newScalar] + $slot : ['value' => $newScalar];
        }
        $indexed[$this->attrCode] = $newSlot;
        $object->updateAttributeIndex($indexed);
        $object->markTouchedByBulkSession($session->getId());
        $this->em->persist(new BulkLog(
            $session->getId(),
            $object->getId(),
            null,
            $slot,
            $newSlot,
            BulkLog::LEVEL_INFO,
            null,
        ));
        ++$counters->success;
    }

    /**
     * Decimal places to keep for a money amount: what the stored value used,
     * floored at 2 so a whole-number price does not lose its grosze.
     */
    private static function scaleOf(mixed $amount): int
    {
        $decimals = 0;
        $text = \is_scalar($amount) ? (string) $amount : '';
        $dot = strpos($text, '.');
        if (false !== $dot) {
            $decimals = \strlen($text) - $dot - 1;
        }

        return max(2, min($decimals, 6));
    }

    private function safeDivide(float $a, float $b): ?float
    {
        if (0.0 === $b) {
            return null;
        }

        return $a / $b;
    }

    private function safeModulo(float $a, float $b): ?float
    {
        if (0.0 === $b) {
            return null;
        }

        return fmod($a, $b);
    }
}
