<?php

use App\Models\User;
use App\Support\Auth\Totp;
use App\Support\Auth\TwoFactorReplayGuard;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

/**
 * A TOTP code is spent exactly once, under real concurrency.
 *
 * # What this test exists to catch
 *
 * W10 kept the replay high-water mark in the cache and spent it in three
 * application steps: read the mark, compare the step against it, write the new
 * mark. Serially that is indistinguishable from the correct implementation, and
 * `TwoFactorTest`'s "refuses a code that has already been accepted" passes
 * against both. The difference only appears when two requests are inside the
 * read-compare-write window at the same time: both read the old mark, both find
 * the step available, both are accepted, and the second factor has been used
 * twice with one set of six digits.
 *
 * That is not a theoretical interleaving. An attacker who has the password (a
 * challenge token cannot be minted without it) and has observed one code sends
 * two challenge requests at once *deliberately* — the narrowness of the window
 * is not a defence when the caller picks the timing.
 *
 * So the concurrency here is real, and the shape follows
 * `tests/Feature/Quota/AtomicQuotaRaceTest.php`, which proves invariant I4 the
 * same way:
 *
 * 1. **Five OS processes.** `pcntl_fork` gives each attempt its own process and
 *    its own PDO connection. The image ships pcntl and posix
 *    (infra/docker/backend.Dockerfile builds them in); on a host without them
 *    this test **skips**, and a skip is not a pass.
 * 2. **Data the children can see.** RefreshDatabase wraps the test in an
 *    uncommitted transaction, so a user created the usual way is invisible to
 *    another connection. The fixture is created on a second connection —
 *    `replayrace`, same database, outside that transaction.
 * 3. **A start barrier, after the connection is warm.** Each child opens its
 *    connection and loads its `User` *before* the barrier, so the only thing
 *    that happens after it is the guard's statement. Otherwise connection
 *    set-up latency spreads the five attempts out and serialises the very race
 *    the test is trying to create.
 * 4. **Proof that they actually overlapped.** Each child records the interval
 *    it spent working and the test asserts that the latest start precedes the
 *    earliest finish. Without that a slow machine would turn this into a loop
 *    and it would still pass.
 *
 * The expected outcome: exactly one child is told the step was free, four are
 * told it was already spent, and the column holds that step.
 */
beforeEach(function () {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl/posix are required to fork real concurrent challengers.');
    }
});

/**
 * A second connection to the same database, outside RefreshDatabase's
 * transaction, so rows written through it are committed and visible to forked
 * children.
 */
function replayRaceConnection(): ConnectionInterface
{
    config(['database.connections.replayrace' => config('database.connections.pgsql')]);

    return DB::connection('replayrace');
}

it('spends a timestep exactly once when five challengers race with the same code', function () {
    $race = replayRaceConnection();
    $reportDir = storage_path('framework/testing/two-factor-race-'.getmypid());
    File::ensureDirectoryExists($reportDir);

    // tenant-scope: bypass-ok the fixture must be COMMITTED for forked children
    // on their own connections to see it; the Eloquent path is inside
    // RefreshDatabase's uncommitted transaction. `users` is a documented
    // CompanyScope exemption in any case (see App\Models\User).
    $companyId = $race->table('companies')->insertGetId([
        'name' => 'Replay Race Fixture '.uniqid(),
        'plan' => 'free',
        'analyzed_feedback_count' => 0,
        'quota_limit' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        // tenant-scope: bypass-ok same committed-fixture reason as above; the
        // row carries an explicit company_id.
        $userId = $race->table('users')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Ada Race',
            'email' => 'race-'.uniqid().'@acme-analytics.com',
            'password' => Hash::make('correct-horse-battery'),
            'role' => User::ROLE_OWNER,
            'email_verified_at' => now(),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // One step, five callers: the situation an attacker replaying an
        // observed code creates on purpose.
        $step = Totp::timestep(1700000000);

        $startAt = microtime(true) + 0.5;
        $pids = [];

        foreach (range(1, 5) as $racer) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork failed; cannot prove atomicity without real processes.');
            }

            if ($pid === 0) {
                runReplayRacingChild($userId, $step, $racer, $startAt, $reportDir);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // ---- the invariant ------------------------------------------------

        $results = collect(File::files($reportDir))
            ->map(fn ($file) => json_decode(File::get($file->getPathname()), true))
            ->filter()
            ->values();

        expect($results)->toHaveCount(5)
            ->and($results->pluck('pid')->unique())->toHaveCount(5);

        // Exactly one. Not "at least one" — a read-then-write implementation
        // hands the same step to every caller that read before the first write,
        // and that is the bug this whole file is about.
        expect($results->where('accepted', true)->count())->toBe(1)
            ->and($results->where('accepted', false)->count())->toBe(4);

        // tenant-scope: bypass-ok reading the committed fixture back through
        // the same explicit-id connection the children wrote on.
        $mark = $race->table('users')->where('id', $userId)->value('two_factor_last_used_step');

        expect((int) $mark)->toBe($step);

        // ---- proof that the five really overlapped ------------------------

        $latestStart = $results->max('started_at');
        $earliestFinish = $results->min('finished_at');

        // If this fails the processes ran one after another, the test proved
        // nothing, and it must say so rather than go green.
        expect($latestStart)->toBeLessThan($earliestFinish);
    } finally {
        // Targeted cleanup of the committed fixture. ON DELETE CASCADE takes
        // the user with the company.
        // tenant-scope: bypass-ok deleting exactly the one committed fixture
        // row this test created, addressed by primary key.
        $race->table('companies')->where('id', $companyId)->delete();
        File::deleteDirectory($reportDir);
        DB::purge('replayrace');
    }
});

/**
 * The body of one forked challenger. Never returns.
 */
function runReplayRacingChild(int $userId, int $step, int $racer, float $startAt, string $reportDir): void
{
    $started = 0.0;
    $accepted = null;

    try {
        // The inherited PDO handles belong to the parent; sharing a socket
        // across processes corrupts both sides. But purging them here is worse
        // than leaving them alone: purge closes the PDO, whose destructor sends
        // a termination packet down the very socket the parent is still using,
        // and the parent's next query dies with "server closed the connection
        // unexpectedly" while rolling back RefreshDatabase's transaction
        // (docs/LESSONS.md).
        //
        // So the inherited handles are never touched. The child resolves
        // everything through a connection name that has never been opened in
        // this process tree, which forces a brand new socket of its own, and
        // nothing destructs it afterwards because the child ends on SIGKILL.
        // `challenger` is a copy of pgsql: same database, outside the parent's
        // uncommitted transaction, which is what the fixture was committed on
        // `replayrace` for.
        config([
            'database.connections.challenger' => config('database.connections.pgsql'),
            'database.default' => 'challenger',
        ]);

        // Everything expensive happens before the barrier: opening the socket
        // and loading the row. What is left after it is one statement, so the
        // five statements land in the same few milliseconds instead of being
        // spread over process start-up.
        $user = User::query()->findOrFail($userId);
        $guard = app(TwoFactorReplayGuard::class);

        while (microtime(true) < $startAt) {
            usleep(200);
        }

        $started = microtime(true);
        $accepted = $guard->spend($user, $step);
    } catch (Throwable) {
        // A child that threw did not accept anything; `accepted` stays null and
        // the parent's count of accepts and refusals will not add up to five,
        // which is the failure it should be.
    } finally {
        @file_put_contents($reportDir.'/'.$racer.'.json', json_encode([
            'pid' => getmypid(),
            'accepted' => $accepted,
            'started_at' => $started,
            'finished_at' => microtime(true),
        ]));
    }

    // SIGKILL rather than exit(): the child must not run PHPUnit's shutdown
    // handlers, which would print a second test summary and flush the parent's
    // buffers all over again. Its work is already committed.
    posix_kill(getmypid(), SIGKILL);
}
