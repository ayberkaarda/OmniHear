<?php

use Tests\Support\Architecture\SourceTree;
use Tests\Support\Architecture\TenantScopeRule;

/**
 * Invariant I1 as a static rule over the source tree.
 *
 * tests/Feature/Tenancy/CompanyScopeTest.php proves the scope *works*. This
 * file proves nothing in the tree walks around it: every DB::table(),
 * DB::select(), DB::statement(), ->toBase() and withoutGlobalScope() in
 * production code carries a written justification, and every migration that
 * creates a business table gives it a company_id.
 *
 * Only two of the three checks the former editor hook carried are here. The
 * third - "a model that mentions company_id must name BelongsToCompany,
 * CompanyScope or addGlobalScope" - is not ported. It was a spelling test
 * standing in for a behaviour, and that behaviour is already asserted, model
 * by model, over a dataset in CompanyScopeTest: query with no tenant throws,
 * find() hides another tenant's row, a listing hides it, count() counts only
 * the tenant in context. A model could satisfy the string check and still fail
 * every one of those; none can pass those and be unscoped. Adding it would
 * only forbid a second correct spelling.
 */

/*
|--------------------------------------------------------------------------
| The scan reaches something
|--------------------------------------------------------------------------
|
| A scanning test that matches nothing is indistinguishable from one that
| works. These two assertions fail loudly if the roots, the extension filter
| or the needle list ever stop pointing at real code.
|
*/

it('scans the production source roots', function () {
    $files = SourceTree::phpFiles(TenantScopeRule::ROOTS);

    expect($files)->not->toBeEmpty()
        ->and($files)->toHaveKey('app/Models/Feedback.php')
        ->and($files)->toHaveKey('routes/console.php');
});

it('still finds the justified bypasses it is meant to be tolerating', function () {
    $sites = [];

    foreach (SourceTree::phpFiles(TenantScopeRule::ROOTS) as $path => $contents) {
        foreach (SourceTree::lines($contents) as $index => $line) {
            if (SourceTree::isCommentLine($line)) {
                continue;
            }

            foreach (TenantScopeRule::NEEDLES as $needle) {
                if (str_contains($line, $needle)) {
                    $sites[] = $path.':'.($index + 1);
                    break;
                }
            }
        }
    }

    // If this ever drops to zero the rule below is passing vacuously.
    expect($sites)->not->toBeEmpty()
        ->and($sites)->toContain(
            'app/Console/Commands/ReprocessAnalysesCommand.php:144',
            'app/Http/Controllers/Api/V1/InvitationController.php:166',
            'app/Support/Connectors/IngestionRunner.php:201',
            'app/Support/Payments/SubscriptionActivator.php:116',
            'routes/console.php:39',
        );
});

/*
|--------------------------------------------------------------------------
| The rule, against the real tree
|--------------------------------------------------------------------------
*/

it('leaves no global-scope bypass unjustified', function () {
    $findings = [];

    foreach (SourceTree::phpFiles(TenantScopeRule::ROOTS) as $path => $contents) {
        $findings = array_merge($findings, TenantScopeRule::scopeBypassFindings($path, $contents));
    }

    // Joined, not compared as an array: a failure then prints the offending
    // file and line rather than a diff of two lists.
    expect(implode("\n", $findings))->toBe('');
});

it('gives every business table a company_id', function () {
    $findings = [];

    foreach (SourceTree::phpFiles(['database/migrations']) as $path => $contents) {
        $findings = array_merge($findings, TenantScopeRule::migrationFindings($path, $contents));
    }

    expect(implode("\n", $findings))->toBe('');
});

/*
|--------------------------------------------------------------------------
| The rule, against code written to break it
|--------------------------------------------------------------------------
|
| Each pair is the same snippet twice: once offending, once justified.
|
*/

it('catches every bypass construct when nothing justifies it', function (string $needle) {
    $source = <<<PHP
    <?php

    class Probe
    {
        public function run(): int
        {
            return {$needle};
        }
    }
    PHP;

    expect(TenantScopeRule::scopeBypassFindings('app/Probe.php', $source))->toHaveCount(1);
})->with([
    "DB::table('feedbacks')->count()",
    "DB::select('select count(*) from feedbacks')",
    "DB::statement('set local statement_timeout = 0')",
    'Feedback::query()->toBase()->count()',
    'Feedback::query()->withoutGlobalScope(CompanyScope::class)->count()',
]);

it('accepts a justification on the offending line itself', function () {
    $offending = "        return DB::table('feedbacks')->count();";
    $justified = $offending.' // '.TenantScopeRule::MARKER.' aggregate over the tenant table itself';

    expect(TenantScopeRule::scopeBypassFindings('app/Probe.php', $offending))->toHaveCount(1)
        ->and(TenantScopeRule::scopeBypassFindings('app/Probe.php', $justified))->toBe([]);
});

it('accepts a justification in the comment block above the statement', function () {
    // The shape in app/Support/Payments/SubscriptionActivator.php:114-116: the
    // marker sits above the statement, two lines above the offending line.
    $lines = [
        '        $owner = Subscription::query()',
        '            ->withoutGlobalScope(CompanyScope::class)',
        "            ->value('company_id');",
    ];

    $offending = implode("\n", $lines);
    $justified = '        // '.TenantScopeRule::MARKER." cross-tenant uniqueness check\n".$offending;

    expect(TenantScopeRule::scopeBypassFindings('app/Probe.php', $offending))->toHaveCount(1)
        ->and(TenantScopeRule::scopeBypassFindings('app/Probe.php', $justified))->toBe([]);
});

it('accepts a justification written inside the fluent chain', function () {
    // The shape in app/Console/Commands/ReprocessAnalysesCommand.php:139-144.
    $offending = implode("\n", [
        '        $query = AiAnalysis::query()',
        '            ->withoutGlobalScope(CompanyScope::class)',
        "            ->select(['id']);",
    ]);

    $justified = implode("\n", [
        '        $query = AiAnalysis::query()',
        '            // '.TenantScopeRule::MARKER.' a console sweep has no tenant context, and',
        '            // every dispatched job re-enters its own company through TenantAwareJob.',
        '            ->withoutGlobalScope(CompanyScope::class)',
        "            ->select(['id']);",
    ]);

    expect(TenantScopeRule::scopeBypassFindings('app/Probe.php', $offending))->toHaveCount(1)
        ->and(TenantScopeRule::scopeBypassFindings('app/Probe.php', $justified))->toBe([]);
});

it('does not let a justification reach across an unrelated statement', function () {
    // A marker three statements up must not launder a later bypass.
    $source = implode("\n", [
        '        // '.TenantScopeRule::MARKER.' this excuses the line below it, nothing else',
        '        $a = Company::query()->count();',
        '',
        "        return DB::table('feedbacks')->count();",
    ]);

    expect(TenantScopeRule::scopeBypassFindings('app/Probe.php', $source))->toHaveCount(1);
});

it('does not read a bypass out of prose', function () {
    // Documentation may name the construct it is warning about.
    $source = implode("\n", [
        '    /**',
        '     * Never reach for DB::table( here - it walks around CompanyScope.',
        '     */',
    ]);

    expect(TenantScopeRule::scopeBypassFindings('app/Probe.php', $source))->toBe([]);
});

it('flags a migration that creates a business table with no company_id', function () {
    $source = <<<'PHP'
    <?php

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('exports', function (Blueprint $table) {
                $table->id();
                $table->string('format');
                $table->timestampsTz();
            });
        }
    };
    PHP;

    expect(TenantScopeRule::migrationFindings('database/migrations/2026_09_05_000001_create_exports_table.php', $source))
        ->toHaveCount(1);
});

it('accepts the same migration once the tenant column is there', function () {
    $source = <<<'PHP'
    <?php

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('exports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('format');
                $table->timestampsTz();
            });
        }
    };
    PHP;

    expect(TenantScopeRule::migrationFindings('database/migrations/2026_09_05_000001_create_exports_table.php', $source))
        ->toBe([]);
});

it('accepts a genuinely global table by name', function () {
    $source = <<<'PHP'
    <?php

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id')->unique();
            });
        }
    };
    PHP;

    expect(TenantScopeRule::migrationFindings('database/migrations/2026_09_05_000002_create_webhook_events_table.php', $source))
        ->toBe([]);
});

it('ignores files outside database/migrations', function () {
    $source = "Schema::create('exports', function (Blueprint \$table) {});";

    expect(TenantScopeRule::migrationFindings('app/Support/Exports/Installer.php', $source))->toBe([]);
});
