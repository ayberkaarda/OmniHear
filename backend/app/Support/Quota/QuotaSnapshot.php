<?php

namespace App\Support\Quota;

/**
 * The state of a company's counter as observed by one atomic increment.
 *
 * `used` is the value the reserving UPDATE returned, so two concurrent
 * reservations can never observe the same number: PostgreSQL serializes the
 * writes on the row, and `RETURNING` hands each caller its own result. That is
 * what makes `crossedWarningThreshold()` exactly-once without a flag column.
 */
final class QuotaSnapshot
{
    public function __construct(
        public readonly int $used,
        public readonly int $limit,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    /**
     * True only for the single reservation that takes usage across the soft
     * warning line (spec 7.3).
     *
     * The alternative - "used >= 80% of limit" - is true for every increment
     * after the crossing and would mail the owner once per analysis. A flag
     * column on `companies` would also work, but the schema is frozen for this
     * phase and the transition test is both cheaper and race-free: exactly one
     * increment can return the threshold value.
     */
    public function crossedWarningThreshold(float $fraction): bool
    {
        if ($this->limit <= 0 || $fraction <= 0.0) {
            return false;
        }

        $threshold = (int) ceil($this->limit * $fraction);

        return $this->used >= $threshold && ($this->used - 1) < $threshold;
    }
}
