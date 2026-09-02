<?php

use App\Jobs\AnalyzeFeedbackJob;
use App\Models\Feedback;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\AiServiceFake;

/**
 * Invariant I4 - the counter is atomic under real concurrency.
 *
 * # Why this test is shaped the way it is
 *
 * A loop that calls the job five times in a row proves nothing about
 * atomicity: it is exactly the interleaving that never happens in production.
 * The bug this invariant exists to prevent - two workers reading 2/3 and both
 * writing 3 - only appears when two processes are inside the read-modify-write
 * window at the same time.
 *
 * So the concurrency here is real:
 *
 * 1. **Five OS processes.** `pcntl_fork` gives each attempt its own process,
 *    its own PDO connection and its own transaction state. The image ships
 *    pcntl and posix (infra/docker/backend.Dockerfile builds them in).
 * 2. **Data that the children can see.** RefreshDatabase wraps the test in an
 *    uncommitted transaction on the `pgsql` connection, so anything created the
 *    usual way is invisible to another connection. The fixtures are therefore
 *    created on a second connection - `race`, same database, outside that
 *    transaction - and the children are pointed at it.
 * 3. **A start barrier.** Every child busy-waits until one shared wall-clock
 *    instant before touching the database, so the five UPDATEs are issued into
 *    the same few milliseconds instead of being spread over process start-up.
 * 4. **Proof that they actually overlapped.** Each child records the interval
 *    it spent working. The test asserts that the latest start precedes the
 *    earliest finish, i.e. that all five were in flight simultaneously. Without
 *    that assertion a machine slow enough to serialise the forks would turn
 *    this into the loop it is trying not to be, and it would still pass.
 *
 * The expected outcome, with `quota_limit = 3` and five racers: the counter
 * lands on exactly 3, three feedback rows are `analyzed`, and the two that lost
 * are parked in `pending_analysis` (spec 7.4) rather than failed or deleted.
 */
beforeEach(function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }

    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl/posix are required to fork real concurrent workers.');
    }
});

/**
 * A second connection to the same database, outside RefreshDatabase's
 * transaction, so rows written through it are committed and visible to forked
 * children.
 */
function raceConnection(): ConnectionInterface
{
    config(['database.connections.race' => config('database.connections.pgsql')]);

    return DB::connection('race');
}

it('increments exactly quota_limit times when five workers race', function () {
    $race = raceConnection();
    $reportDir = storage_path('framework/testing/quota-race-'.getmypid());
    File::ensureDirectoryExists($reportDir);

    // tenant-scope: bypass-ok fixtures must be COMMITTED for forked children on
    // their own connections to see them; the Eloquent path is inside
    // RefreshDatabase's uncommitted transaction. Every row is written with an
    // explicit company_id, so nothing here relies on ambient tenant state.
    $companyId = $race->table('companies')->insertGetId([
        'name' => 'Race Fixture '.uniqid(),
        'plan' => 'free',
        'analyzed_feedback_count' => 0,
        'quota_limit' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        // tenant-scope: bypass-ok same committed-fixture reason as above.
        $integrationId = $race->table('integrations')->insertGetId([
            'company_id' => $companyId,
            'platform' => 'fixture',
            'settings' => json_encode(['locale' => 'en']),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feedbackIds = [];

        foreach (range(1, 5) as $n) {
            // tenant-scope: bypass-ok same committed-fixture reason as above.
            $feedbackIds[] = $race->table('feedbacks')->insertGetId([
                'company_id' => $companyId,
                'integration_id' => $integrationId,
                'external_id' => 'race-'.uniqid().'-'.$n,
                'author' => 'Racer '.$n,
                'body' => 'The app crashes every time I open the camera.',
                'source_url' => null,
                'published_at' => now(),
                'raw_payload' => json_encode(['source' => 'race']),
                'analysis_status' => Feedback::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Installed in the parent so every child inherits it: no child talks to
        // the real analyzer, and the analyzer is not what is under test here.
        AiServiceFake::fakeSuccess();

        $startAt = microtime(true) + 0.5;
        $pids = [];

        foreach ($feedbackIds as $feedbackId) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork failed; cannot prove atomicity without real processes.');
            }

            if ($pid === 0) {
                runRacingChild($companyId, $feedbackId, $startAt, $reportDir);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // ---- the invariant ------------------------------------------------

        // tenant-scope: bypass-ok reading the committed race fixtures back
        // through the same explicit-id connection the children wrote on.
        $company = $race->table('companies')->where('id', $companyId)->first();

        $statuses = $race->table('feedbacks')
            ->where('company_id', $companyId)
            ->selectRaw('analysis_status, count(*) as aggregate')
            ->groupBy('analysis_status')
            ->pluck('aggregate', 'analysis_status');

        expect((int) $company->analyzed_feedback_count)->toBe(3)
            ->and((int) ($statuses[Feedback::STATUS_ANALYZED] ?? 0))->toBe(3)
            // Spec 7.4: the two that lost the race wait, they do not fail and
            // they are not deleted.
            ->and((int) ($statuses[Feedback::STATUS_PENDING] ?? 0))->toBe(2)
            ->and((int) ($statuses[Feedback::STATUS_FAILED] ?? 0))->toBe(0)
            ->and($race->table('feedbacks')->where('company_id', $companyId)->count())->toBe(5);

        // ---- proof that the five really overlapped ------------------------

        $intervals = collect(File::files($reportDir))
            ->map(fn ($file) => json_decode(File::get($file->getPathname()), true))
            ->filter()
            ->values();

        expect($intervals)->toHaveCount(5)
            ->and($intervals->pluck('pid')->unique())->toHaveCount(5);

        $latestStart = $intervals->max('started_at');
        $earliestFinish = $intervals->min('finished_at');

        // If this fails the processes ran one after another and the test would
        // have proved nothing, so it is an assertion rather than a comment.
        expect($latestStart)->toBeLessThan($earliestFinish);
    } finally {
        // Targeted cleanup of the committed fixtures. ON DELETE CASCADE takes
        // the integration, the feedbacks and the analyses with the company.
        // tenant-scope: bypass-ok deleting exactly the one committed fixture
        // row this test created, addressed by primary key.
        $race->table('companies')->where('id', $companyId)->delete();
        File::deleteDirectory($reportDir);
        DB::purge('race');
    }
});

/**
 * The body of one forked worker. Never returns.
 */
function runRacingChild(int $companyId, int $feedbackId, float $startAt, string $reportDir): void
{
    $started = 0.0;

    try {
        // The inherited PDO handles belong to the parent; sharing a socket
        // across processes corrupts both sides. But purging them here is worse
        // than leaving them alone: purge closes the PDO, whose destructor sends
        // a termination packet down the very socket the parent is still using,
        // and the parent's next query dies with "server closed the connection
        // unexpectedly" while rolling back RefreshDatabase's transaction.
        //
        // So the inherited handles are never touched at all. Instead the child
        // resolves everything through a connection name that has never been
        // opened in this process tree, which forces a brand new socket of its
        // own. Nothing destructs them afterwards either, because the child ends
        // on SIGKILL. `child` is a copy of pgsql, so it is the same database -
        // just outside the parent's uncommitted transaction, which is what the
        // fixtures were committed on `race` for.
        config([
            'database.connections.child' => config('database.connections.pgsql'),
            'database.default' => 'child',
        ]);

        while (microtime(true) < $startAt) {
            usleep(200);
        }

        $started = microtime(true);

        (new AnalyzeFeedbackJob($companyId, $feedbackId))->handle(app(TenantContext::class));
    } catch (Throwable) {
        // The parent asserts on database state, not on child exit codes; a
        // child that throws simply did not win the race.
    } finally {
        @file_put_contents($reportDir.'/'.$feedbackId.'.json', json_encode([
            'pid' => getmypid(),
            'started_at' => $started,
            'finished_at' => microtime(true),
        ]));
    }

    // SIGKILL rather than exit(): the child must not run PHPUnit's shutdown
    // handlers, which would print a second test summary and flush the parent's
    // buffers all over again. Its work is already committed.
    posix_kill(getmypid(), SIGKILL);
}
