<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    | The 'defaults' block below (config('ai.queue')) explains why this file
    | can resolve config('ai.queue') at all — config/ai.php sorts before
    | config/horizon.php in Laravel's config loader, so it is already loaded.
    | Same reasoning applies here: the analysis queue is the one W9 fixed
    | Horizon to actually drain, so it needs its own wait threshold, not just
    | 'default'. 'analysis' is never written here as a literal for the same
    | drift-safety reason as the supervisor's queue list below.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:'.config('ai.queue') => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    | This file did not exist before this fix, so every supervisor ran on the
    | package default of `'queue' => ['default']`. AnalyzeFeedbackJob dispatches
    | onto config('ai.queue') (config/ai.php, 'analysis' unless
    | AI_ANALYSIS_QUEUE overrides it) precisely so a burst of ingestion cannot
    | starve analysis - but nothing was ever watching that queue. Measured:
    | FetchFeedbackJob ran, five AnalyzeFeedbackJob payloads piled up in
    | omnihear_database_queues:analysis, and none of them ever executed
    | (docs/LESSONS.md).
    |
    | 'analysis' is never written here as a literal: config('ai.queue') is read
    | instead, so the two definitions cannot drift out of sync the way the
    | compose-level AI_ANALYSIS_QUEUE stopgap could have. This is safe only
    | because Laravel's config loader (LoadConfiguration::getConfigurationFiles)
    | sorts config/ files with ksort(..., SORT_NATURAL) before requiring them,
    | and 'ai' sorts before 'horizon' - so config('ai.queue') already has a
    | value by the time this file is evaluated. If config/ai.php is ever
    | renamed to sort after config/horizon.php, this reference breaks silently
    | (config('ai.queue') would return null and the supervisor would watch
    | ['default', null]) - a fixture-shape test cannot catch a rename, only a
    | file list diff would.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default', config('ai.queue')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | ProvisioningPlan::deploy() (vendor/laravel/horizon/src/ProvisioningPlan.php)
    | matches APP_ENV against these keys with Str::is(), not array_key_exists(),
    | and silently deploys zero supervisors - no error, `horizon:status` still
    | says "running" - when nothing matches. This is the same class of bug as
    | the Reverb TLS-to-itself finding and the Broadcast::channel() boot-order
    | finding in docs/LESSONS.md: both sides green, the path between them dead.
    | The 'defaults' change above is worthless if this array does not also
    | cover the environment the stack actually runs in.
    |
    | backend/.env.example ships APP_ENV=local, and infra/docker-compose.dev.yml
    | sets no APP_ENV override for the backend/horizon services, so 'local'
    | (kept from the package default, unmodified below) is what the dev compose
    | stack matches.
    |
    | 'testing' is deliberately absent. backend/tests/bootstrap.php forces
    | QUEUE_CONNECTION=sync and APP_ENV=testing for the whole suite; jobs run
    | inline and `php artisan horizon` is never invoked there, so
    | ProvisioningPlan::deploy() is dead code under Pest regardless of what
    | this array contains. Adding a 'testing' key would exercise no path this
    | codebase runs.
    |
    | A '*' catch-all was tried here and removed on purpose. It would have
    | matched any unlisted APP_ENV and started a modest worker, as a guard
    | against ProvisioningPlan::deploy()'s silent no-op. Two reasons it is
    | worse than the hole it plugs. First, it hides the same class of fault it
    | claims to defend: a typo'd APP_ENV would quietly get workers and nobody
    | would learn the name was wrong - the silence moves, it does not go away.
    | Second, it weakens the test below it: with a wildcard present, deleting
    | the 'local' block leaves Str::is('*', 'local') answering true, so
    | HorizonQueueCoverageTest stays green while the environment it is meant to
    | pin has vanished. D-09 closed the environment set to {local, testing},
    | and 'testing' never runs Horizon - so there is no unlisted environment
    | left for a catch-all to serve.
    |
    */

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
