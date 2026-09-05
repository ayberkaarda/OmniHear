<?php

use App\Models\User;
use App\Support\Auth\TwoFactorChallenge;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

/**
 * The challenge attempt budget fills exactly once per wrong code, under real
 * concurrency.
 *
 * # What this test exists to catch
 *
 * W10 kept the per-token attempt counter in the cache and spent it with
 * `Cache::get` + `Cache::put`: read the count, add one, write it back. Serially
 * that is indistinguishable from a correct counter, and
 * `TwoFactorTest`'s "destroys the challenge token once the attempt budget is
 * spent" passes against both. The difference only appears when two wrong-code
 * requests are inside the read-modify-write window at the same time: both read
 * the same count, both write count+1, and five simultaneous guesses advance the
 * budget by one. The five-attempt cap never fills, so an attacker who has the
 * password and is walking the six-digit space against one account is bounded by
 * nothing but the IP throttle - which a botnet spends one address at a time.
 *
 * That is not a theoretical interleaving. A challenge token is minted from a
 * password alone; an attacker who holds it fires wrong codes in parallel *on
 * purpose*, and the narrowness of the window is not a defence when the caller
 * picks the timing.
 *
 * `phpunit.xml` pins `CACHE_STORE=array`, which is per-process, so a cache-based
 * counter cannot even be observed across forked children - one more reason the
 * counter had to become a `users` column. As a column the increment is a single
 * `UPDATE … SET col = COALESCE(col, 0) + 1 … RETURNING`, and PostgreSQL - not
 * application code - serialises the concurrent increments.
 *
 * The shape follows `tests/Feature/Auth/TwoFactorReplayRaceTest.php` and
 * `tests/Feature/Quota/AtomicQuotaRaceTest.php`, which prove the sibling races
 * the same way:
 *
 * 1. **Five OS processes.** `pcntl_fork` gives each attempt its own process and
 *    its own PDO connection. The image ships pcntl and posix
 *    (infra/docker/backend.Dockerfile builds them in); on a host without them
 *    this test **skips**, and a skip is not a pass.
 * 2. **Data the children can see.** RefreshDatabase wraps the test in an
 *    uncommitted transaction, so a user created the usual way is invisible to
 *    another connection. The fixture is created on a second connection -
 *    `attemptrace`, same database, outside that transaction.
 * 3. **A start barrier, after the connection is warm.** Each child opens its
 *    connection and loads its `User` *before* the barrier, so the only thing
 *    that happens after it is the guard's statement.
 * 4. **Proof that they actually overlapped.** Each child records the interval it
 *    spent working and the test asserts the latest start precedes the earliest
 *    finish. Without it a slow machine would serialise the forks into a loop and
 *    the test would still pass.
 *
 * The expected outcome, with `MAX_ATTEMPTS = 5` and five racers against a fresh
 * (null) counter: every increment lands, so the column holds exactly 5, and
 * exactly one child - the one whose increment returned 5 - is told the budget is
 * now spent. The read-modify-write version lands the column below 5 and tells
 * nobody the budget is spent, which is the bug this file is about.
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
function attemptRaceConnection(): ConnectionInterface
{
    config(['database.connections.attemptrace' => config('database.connections.pgsql')]);

    return DB::connection('attemptrace');
}

it('fills the attempt budget exactly once per wrong code when five challengers race', function () {
    $race = attemptRaceConnection();
    $reportDir = storage_path('framework/testing/two-factor-attempt-race-'.getmypid());
    File::ensureDirectoryExists($reportDir);

    // tenant-scope: bypass-ok the fixture must be COMMITTED for forked children
    // on their own connections to see it; the Eloquent path is inside
    // RefreshDatabase's uncommitted transaction. `users` is a documented
    // CompanyScope exemption in any case (see App\Models\User).
    $companyId = $race->table('companies')->insertGetId([
        'name' => 'Attempt Race Fixture '.uniqid(),
        'plan' => 'free',
        'analyzed_feedback_count' => 0,
        'quota_limit' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        // tenant-scope: bypass-ok same committed-fixture reason as above; the
        // row carries an explicit company_id. The attempt counter starts null,
        // i.e. no wrong code has been recorded yet.
        $userId = $race->table('users')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Ada Attempt',
            'email' => 'attempt-'.uniqid().'@acme-analytics.com',
            'password' => Hash::make('correct-horse-battery'),
            'role' => User::ROLE_OWNER,
            'email_verified_at' => now(),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
            'two_factor_challenge_attempts' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Five wrong codes, fired at once: the situation an attacker with the
        // password and a challenge token creates deliberately.
        $racers = TwoFactorChallenge::MAX_ATTEMPTS;

        $startAt = microtime(true) + 0.5;
        $pids = [];

        foreach (range(1, $racers) as $racer) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork failed; cannot prove atomicity without real processes.');
            }

            if ($pid === 0) {
                runAttemptRacingChild($userId, $racer, $startAt, $reportDir);
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

        expect($results)->toHaveCount($racers)
            ->and($results->pluck('pid')->unique())->toHaveCount($racers);

        // tenant-scope: bypass-ok reading the committed fixture back through the
        // same explicit-id connection the children wrote on.
        $counter = $race->table('users')->where('id', $userId)->value('two_factor_challenge_attempts');

        // Every increment landed. Not "at most 5" - a read-then-write
        // implementation loses the ones that read a stale count, and the budget
        // ends below the cap, which is the whole bug this file is about.
        expect((int) $counter)->toBe($racers);

        // Exactly one caller crosses the cap and is told the budget is spent.
        // With the counter landing on 1,2,3,4,5 across the five atomic
        // increments, only the increment that returned 5 reports true.
        expect($results->where('spent', true)->count())->toBe(1)
            ->and($results->where('spent', false)->count())->toBe($racers - 1);

        // ---- proof that the five really overlapped ------------------------

        $latestStart = $results->max('started_at');
        $earliestFinish = $results->min('finished_at');

        // If this fails the processes ran one after another, the test proved
        // nothing, and it must say so rather than go green.
        expect($latestStart)->toBeLessThan($earliestFinish);
    } finally {
        // Targeted cleanup of the committed fixture. ON DELETE CASCADE takes the
        // user with the company.
        // tenant-scope: bypass-ok deleting exactly the one committed fixture row
        // this test created, addressed by primary key.
        $race->table('companies')->where('id', $companyId)->delete();
        File::deleteDirectory($reportDir);
        DB::purge('attemptrace');
    }
});

/**
 * The body of one forked challenger. Never returns.
 */
function runAttemptRacingChild(int $userId, int $racer, float $startAt, string $reportDir): void
{
    $started = 0.0;
    $spent = null;

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
        // `attemptrace` for.
        config([
            'database.connections.challenger' => config('database.connections.pgsql'),
            'database.default' => 'challenger',
        ]);

        // Everything expensive happens before the barrier: opening the socket
        // and loading the row. What is left after it is one statement, so the
        // five statements land in the same few milliseconds instead of being
        // spread over process start-up.
        $user = User::query()->findOrFail($userId);
        $challenges = app(TwoFactorChallenge::class);

        while (microtime(true) < $startAt) {
            usleep(200);
        }

        $started = microtime(true);
        $spent = $challenges->recordFailure($user);
    } catch (Throwable) {
        // A child that threw recorded nothing; `spent` stays null and the
        // parent's count of true and false will not add up to five, which is the
        // failure it should be.
    } finally {
        @file_put_contents($reportDir.'/'.$racer.'.json', json_encode([
            'pid' => getmypid(),
            'spent' => $spent,
            'started_at' => $started,
            'finished_at' => microtime(true),
        ]));
    }

    // SIGKILL rather than exit(): the child must not run PHPUnit's shutdown
    // handlers, which would print a second test summary and flush the parent's
    // buffers all over again. Its work is already committed.
    posix_kill(getmypid(), SIGKILL);
}
