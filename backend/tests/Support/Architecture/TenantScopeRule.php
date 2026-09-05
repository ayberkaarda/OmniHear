<?php

namespace Tests\Support\Architecture;

/**
 * Invariant I1, enforced statically.
 *
 * Two rules, both formerly carried by an editor-side hook that could only
 * *warn* about the file it had just seen written. They are project rules, not
 * tooling rules, so they live here instead: the whole tree is checked, on
 * every run, by whoever runs the suite.
 *
 * 1. scopeBypassFindings() - the five constructs that step around the
 *    CompanyScope global scope must carry a written justification.
 * 2. migrationFindings() - a Schema::create() for a business table must give
 *    it a company_id.
 *
 * A third check the hook carried, "a model mentioning company_id must use
 * BelongsToCompany / CompanyScope / addGlobalScope", is deliberately absent:
 * tests/Feature/Tenancy/CompanyScopeTest.php already proves the *behaviour*
 * that check was a proxy for, over a dataset of every scoped model, and a
 * behavioural proof beats a spelling test.
 */
final class TenantScopeRule
{
    public const MARKER = 'tenant-scope: bypass-ok';

    /**
     * Where production queries live. `database/` is excluded because
     * migrations answer to the separate rule below, and `tests/` because a
     * test may legitimately look at another tenant's rows to prove they cannot
     * be reached through the application.
     *
     * The hook additionally skipped app/Console/ and app/Providers/. That
     * exemption is not reproduced: both existing cross-tenant sweeps there
     * already carry a justification, so the blind spot bought nothing, and the
     * scheduler is exactly where an unnoticed unscoped query does most damage.
     *
     * @var list<string>
     */
    public const ROOTS = ['app', 'routes'];

    /**
     * Constructs that bypass the Eloquent global scope. Substrings, matched on
     * the source line, exactly as the hook matched them.
     *
     * @var list<string>
     */
    public const NEEDLES = [
        'DB::table(',
        'DB::select(',
        'DB::statement(',
        '->toBase()',
        'withoutGlobalScope(',
    ];

    /**
     * Tables that legitimately carry no company_id.
     *
     * Everything but `users` is Laravel's own furniture or, in the case of
     * `companies` and `webhook_events`, documented in the spec: the tenant
     * table itself, and the one table written before a tenant is known.
     * `users` was missing from the hook's copy of this list only because
     * Laravel's stock create_users_table migration predates the hook and was
     * never written through it - the hook would have warned on it. It belongs
     * here: users are the tenant *membership* table, their company_id arrives
     * in 2026_09_02_000002_add_tenancy_columns_to_users_table.php, and User is
     * a documented CompanyScope exemption (see app/Models/User.php).
     *
     * @var list<string>
     */
    public const TABLE_ALLOWLIST = [
        'companies', 'users', 'webhook_events', 'migrations', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'password_reset_tokens', 'password_resets', 'sessions',
        'cache', 'cache_locks', 'telescope_entries', 'notifications',
    ];

    /**
     * Unjustified global-scope bypasses in one file.
     *
     * @return list<string> human-readable findings, one per offending line
     */
    public static function scopeBypassFindings(string $relativePath, string $contents): array
    {
        $lines = SourceTree::lines($contents);
        $findings = [];

        foreach ($lines as $index => $line) {
            // A needle inside a comment is prose about the rule, not a query.
            if (SourceTree::isCommentLine($line)) {
                continue;
            }

            foreach (self::NEEDLES as $needle) {
                if (! str_contains($line, $needle)) {
                    continue;
                }

                if (self::isJustified($lines, $index)) {
                    continue;
                }

                $findings[] = $relativePath.':'.($index + 1).' - '.$needle
                    .' bypasses CompanyScope with no `// '.self::MARKER.' <reason>` justification';
            }
        }

        return $findings;
    }

    /**
     * Business tables created without a company_id.
     *
     * @return list<string>
     */
    public static function migrationFindings(string $relativePath, string $contents): array
    {
        if (! str_contains($relativePath, 'database/migrations/')) {
            return [];
        }

        // Justified lines are removed before the file is read, so a marker can
        // excuse the whole migration the same way it did under the hook.
        $active = implode("\n", array_filter(
            SourceTree::lines($contents),
            static fn (string $line): bool => ! str_contains($line, self::MARKER),
        ));

        if (! str_contains($active, 'Schema::create(')) {
            return [];
        }

        if (preg_match('/\bcompany_id\b/', $active) === 1) {
            return [];
        }

        preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $active, $matches);

        $findings = [];

        foreach ($matches[1] as $table) {
            if (in_array(strtolower($table), self::TABLE_ALLOWLIST, true)) {
                continue;
            }

            $findings[] = $relativePath.' - Schema::create("'.$table.'") has no company_id column; '
                .'every business table is tenant-owned (foreignId, constrained, cascadeOnDelete)';
        }

        return $findings;
    }

    /**
     * Does a justification cover the statement the needle on $index belongs to?
     *
     * The marker is accepted anywhere from the top of the comment block above
     * the statement down to the needle itself. Both forms are in the tree and
     * both are correct:
     *
     *   // tenant-scope: bypass-ok cross-tenant uniqueness check
     *   $owner = Subscription::query()
     *       ->withoutGlobalScope(CompanyScope::class)   <- marker two lines up
     *
     *   $query = AiAnalysis::query()
     *       // tenant-scope: bypass-ok a console sweep has no tenant context
     *       ->withoutGlobalScope(CompanyScope::class)   <- marker inside the chain
     *
     * @param  list<string>  $lines
     */
    private static function isJustified(array $lines, int $index): bool
    {
        $from = self::commentBlockStart($lines, self::statementStart($lines, $index));

        for ($i = $from; $i <= $index; $i++) {
            if (str_contains($lines[$i], self::MARKER)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk up to the first line of the statement $index sits in - i.e. past
     * the fluent-chain continuations above it, stopping at anything that ended
     * a previous statement, at a blank line, or at a comment.
     *
     * @param  list<string>  $lines
     */
    private static function statementStart(array $lines, int $index): int
    {
        while ($index > 0) {
            $previous = $lines[$index - 1];
            $trimmed = trim($previous);

            if ($trimmed === '' || SourceTree::isCommentLine($previous)) {
                break;
            }

            if (str_ends_with($trimmed, ';') || str_ends_with($trimmed, '{') || str_ends_with($trimmed, '}')) {
                break;
            }

            $index--;
        }

        return $index;
    }

    /**
     * Walk up through the contiguous comment lines directly above $index.
     *
     * @param  list<string>  $lines
     */
    private static function commentBlockStart(array $lines, int $index): int
    {
        while ($index > 0 && SourceTree::isCommentLine($lines[$index - 1])) {
            $index--;
        }

        return $index;
    }
}
