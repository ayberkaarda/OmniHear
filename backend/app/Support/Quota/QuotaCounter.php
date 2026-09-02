<?php

namespace App\Support\Quota;

use Illuminate\Support\Facades\DB;

/**
 * The atomic analysis counter (invariant I4, spec 6.4 and 7.2).
 *
 * # Why a conditional UPDATE and not a lock
 *
 * The obvious implementation - SELECT the company, compare count to limit,
 * then `increment()` - has a window between the read and the write in which
 * another worker can do the same thing. Five workers can all read 199/200 and
 * all write 200. `lockForUpdate()` closes that window but costs a round trip
 * and holds a row lock across application code.
 *
 * A single `UPDATE ... WHERE analyzed_feedback_count < quota_limit
 * ... RETURNING` has no window at all: PostgreSQL evaluates the predicate and
 * performs the write inside one row lock it takes and releases itself.
 * Concurrent statements queue on that lock and re-evaluate the predicate
 * against the *updated* row, so the (limit - used) th caller is the last one to
 * get a row back and everyone after it gets none.
 *
 * `tests/Feature/Quota/AtomicQuotaRaceTest.php` proves this with five forked
 * OS processes on five separate connections, not with a loop.
 *
 * # Reserve / release
 *
 * `reserve()` is called *before* the analyzer is invoked, so an exhausted
 * quota costs no inference. If the call then fails, `release()` gives the slot
 * back, which keeps spec 7.2's "counter increments per *successful* analysis"
 * true across retries: without it, five retries of one failing analysis would
 * burn five units of a customer's quota.
 */
class QuotaCounter
{
    /**
     * Claim one unit of quota.
     *
     * @return QuotaSnapshot|null null when the quota is exhausted; the caller
     *                            must then park the feedback in
     *                            `pending_analysis` (spec 7.4) rather than fail.
     */
    public function reserve(int $companyId): ?QuotaSnapshot
    {
        // tenant-scope: bypass-ok companies IS the tenant table and is exempt
        // from CompanyScope by contract; the row is addressed by primary key.
        // Eloquent cannot express UPDATE ... RETURNING, and the atomicity of
        // this single statement is the whole point (invariant I4).
        $row = DB::selectOne(
            <<<'SQL'
            UPDATE companies
               SET analyzed_feedback_count = analyzed_feedback_count + 1,
                   updated_at = now()
             WHERE id = ?
               AND analyzed_feedback_count < quota_limit
            RETURNING analyzed_feedback_count, quota_limit
            SQL,
            [$companyId],
            useReadPdo: false,
        );

        if ($row === null) {
            return null;
        }

        return new QuotaSnapshot((int) $row->analyzed_feedback_count, (int) $row->quota_limit);
    }

    /**
     * Hand a reserved unit back after the analysis failed to complete.
     *
     * Guarded by `> 0` so a double release can never drive the counter
     * negative; the counter is a bigint with no CHECK behind it.
     */
    public function release(int $companyId): void
    {
        // tenant-scope: bypass-ok same reason as reserve() above.
        DB::update(
            <<<'SQL'
            UPDATE companies
               SET analyzed_feedback_count = analyzed_feedback_count - 1,
                   updated_at = now()
             WHERE id = ?
               AND analyzed_feedback_count > 0
            SQL,
            [$companyId],
        );
    }
}
