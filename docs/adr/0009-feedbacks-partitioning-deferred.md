# ADR-0009 — Monthly partitioning of `feedbacks` deferred; a composite index is the interim answer

- **Status:** Accepted
- **Date:** 2026-09-03
- **Phase:** W6
- **Related spec:** §5 "Yüksek hacim için `feedbacks` tablosuna `published_at` üzerinden aylık partitioning planlanır."
- **Related contract:** `docs/contracts/backend-core.md` §1 (`feedbacks`)

## Context

Spec §5 plans monthly partitioning of `feedbacks` on `published_at` for high
volume. `docs/contracts/backend-core.md` already states the deferral and names
the interim mitigation: `index (company_id, published_at DESC)` for the inbox,
plus `index (company_id, analysis_status)` for the re-queue sweep. Neither the
migration in
`backend/database/migrations/2026_09_02_000005_create_feedbacks_table.php` nor
any later migration declares `feedbacks` as a partitioned table — it is one
ordinary table with those two indexes, confirmed by reading the migration.

This project has no production traffic. `DemoCompanySeeder` puts 60 rows in the
table; the largest fixture-driven test run in `docs/PROGRESS.md` is in the
thousands. Partitioning a table that size would add operational surface —
partition creation has to be scheduled ahead of the data that needs it — for a
problem that does not exist yet.

## Decision

Defer monthly partitioning. Ship the composite index instead, and record here
the volume and access pattern at which the index stops being adequate, so the
decision can be revisited on evidence rather than by feel.

**The index is adequate as long as the inbox and the re-queue sweep are the
only things that scan `feedbacks` at scale.** Both are satisfied entirely by
`(company_id, published_at DESC)`: PostgreSQL walks the index in the exact
order the inbox renders, touching only the rows one tenant owns. A B-tree
index degrades log-arithmically, not linearly, so a growing table does not by
itself force a reconsideration — what forces one is a change in *how* the
table is queried.

**Signals that would move this from "deferred" to "do it now":**

1. **A single company's row count starts dominating index and vacuum cost.**
   The composite index is per-tenant only in its leading column; a company
   with tens of millions of rows still pays for a B-tree that size on every
   write, and `VACUUM`/`ANALYZE` on the whole table serialises behind that
   company's churn even for tenants with a handful of rows. Partitioning by
   `published_at` bounds both by month regardless of which tenant wrote the
   rows.
2. **A query needs to scan across companies by time** (a cross-tenant
   operational report, a scheduled cleanup, an audit sweep) rather than
   within one company's `company_id` prefix. The composite index cannot serve
   that shape efficiently; a `published_at`-based partition can, because
   `published_at` is the partition key rather than a secondary column.
3. **Old-data retention or archival becomes a requirement.** Dropping a
   partition is an O(1) metadata operation; deleting a year of rows out of one
   unpartitioned table is a multi-hour, WAL-heavy, autovacuum-triggering
   operation on a table still serving live traffic.
4. **`ai_analyses`, which is 1:1 with `feedbacks` and carries its own
   `company_id` specifically so KPI aggregation avoids a join** (per
   `docs/contracts/backend-core.md` §1), starts showing the same symptoms —
   because at that point the pair should partition together, not separately.

None of the four is true today, and `docs/PROGRESS.md` records no company
whose row count is even a rounding error toward signal 1.

### What implementing it would involve

PostgreSQL 16 declarative partitioning (`PARTITION BY RANGE (published_at)`),
monthly ranges. Concretely:

- **The primary key changes shape.** PostgreSQL requires the partition key to
  be part of every unique constraint on a partitioned table, so
  `UNIQUE(integration_id, external_id)` — invariant I2 — would need to become
  `UNIQUE(integration_id, external_id, published_at)`, and application code
  that assumes a bare `id` uniquely locates a row still works (the primary key
  stays unique) but any raw SQL touching the unique constraint has to change.
- **A partition-creation job.** `pg_partman` or a scheduled Laravel command
  that creates next month's partition ahead of time; missing one means inserts
  for a month with no partition fail outright, which is a worse failure mode
  than an unpartitioned table ever has.
- **Migration of existing data.** Converting a live unpartitioned table to
  partitioned requires either `pg_partman`'s online conversion path or a
  create-new-table-and-swap, both of which need a maintenance window or careful
  online tooling; this is the most operationally risky part of implementing
  the ADR later rather than now.
- **`CompanyScope` and every `BelongsToCompany` query are unaffected** —
  partitioning is transparent to a query that already filters on
  `company_id` and `published_at`; PostgreSQL's partition pruning uses the
  same `WHERE` clause the tenant scope already adds.
- **Re-validating the two existing indexes per-partition**, since PostgreSQL
  creates local indexes per partition rather than one global index by default.

## Alternatives considered

- **Partition now, ahead of any measured need.** Rejected. Partitioning adds a
  partition-creation job and a primary-key shape change to every code path that
  touches `feedbacks.id` directly, in exchange for a performance property this
  tree cannot yet measure — there is no data at the volume where a B-tree scan
  of `(company_id, published_at DESC)` would show a measurable cost over a
  partition-pruned equivalent. Speculative infrastructure that cannot be
  benchmarked against the problem it solves is not a decision, it is a guess.
- **Range-partition by `company_id` instead of `published_at`.** Rejected as
  solving the wrong axis: the composite index already isolates one tenant's
  scan efficiently, and the actual pressure this ADR anticipates (signal 1
  above) is a single large tenant's row count, which `company_id` partitioning
  does not bound — one company can still land in one partition of unbounded
  size.
- **Do nothing, and never revisit.** Rejected. Spec §5 states the plan
  explicitly; recording the interim answer and the trigger conditions is what
  keeps this decision reversible on evidence instead of becoming silent
  technical debt nobody remembers making.

## Consequences

**Positive.** Zero added operational surface today — no partition-creation
job to keep running, no primary-key shape change to carry through every
migration and raw query. The composite index already matches both real access
patterns (inbox render, re-queue sweep) exactly.

**Negative / accepted debt.** The unique constraint (`integration_id,
external_id`) will need to widen to include `published_at` on the day this is
implemented, which is a breaking-shape migration for invariant I2 — better to
know that now than discover it mid-migration. A single-tenant pathological
growth case (signal 1) degrades gradually rather than being bounded from day
one; nothing today detects that signal automatically, so it depends on someone
reading table size during a future phase gate.

**Not covered.** No monitoring exists yet that would surface signal 1 (per-
tenant row count) on its own; this ADR does not add one, because building
alerting for a volume this project has never reached would be exactly the kind
of speculative work the "alternatives" section above rejects for partitioning
itself.

## Related spec section

`docs/OMNIHEAR-SPEC.md` §5, `docs/contracts/backend-core.md` §1
(`feedbacks`).
