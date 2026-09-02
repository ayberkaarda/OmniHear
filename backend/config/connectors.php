<?php

use App\Support\Connectors\AppStoreConnector;
use App\Support\Connectors\FixtureConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | Fixture root
    |--------------------------------------------------------------------------
    |
    | Where FixtureConnector reads its pages from, and where the recorded
    | platform responses live.
    |
    | It is under tests/ rather than under the repository's contracts/ directory
    | for one concrete reason: the dev compose stack bind-mounts only
    | `../backend` into the backend container (infra/docker-compose.dev.yml), so
    | `contracts/` does not exist at run time and nothing inside the container
    | can read it. `contracts/fixtures/platforms/appstore/` keeps the provenance
    | copy of the recorded App Store responses; this is the copy the code and
    | the suite actually load. Override the path rather than moving the files if
    | a mount is ever added.
    |
    */

    'fixtures_path' => env('CONNECTOR_FIXTURES_PATH', base_path('tests/Fixtures/platforms')),

    /*
    |--------------------------------------------------------------------------
    | Sync lock
    |--------------------------------------------------------------------------
    |
    | How long a single integration's sync may hold its lock before another run
    | is allowed to start. Long enough to cover the worst paged run plus queue
    | backoff, short enough that a worker killed mid-run does not wedge the
    | integration until someone notices. While the lock is held,
    | POST /integrations/{id}/sync answers 409 SYNC_IN_PROGRESS.
    |
    */

    'sync_lock_ttl' => (int) env('CONNECTOR_SYNC_LOCK_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Spec 6.1: the scheduler dispatches FetchFeedbackJob for active
    | integrations every five minutes.
    |
    */

    'schedule' => [
        'chunk' => (int) env('CONNECTOR_SCHEDULE_CHUNK', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platforms
    |--------------------------------------------------------------------------
    |
    | Only the platforms listed here may be created through the API. The schema
    | allows more values (backend-core.md section 1) because later phases add
    | them; an integration for a platform with no connector would sit in the
    | scheduler failing forever, so the create endpoint refuses it up front.
    |
    | `rate_limit` is the per-platform throttle spec 6.1 asks for. It is keyed
    | by platform, not by integration: the limit belongs to the third party, and
    | every tenant syncing the same platform shares it.
    |
    */

    'platforms' => [

        'fixture' => [
            'connector' => FixtureConnector::class,
            'required_settings' => [],
            'max_pages_per_run' => 10,
            'max_consecutive_empty_pages' => 3,
            'rate_limit' => ['max_attempts' => 600, 'decay_seconds' => 60],
            'retry_after' => 5,
        ],

        'appstore' => [
            'connector' => AppStoreConnector::class,
            'base_url' => env('APPSTORE_RSS_BASE_URL', 'https://itunes.apple.com'),
            'required_settings' => ['app_id', 'country'],
            // Hard platform ceiling, measured 2026-09-02: page=11 answers HTTP
            // 400 "CustomerReviews RSS page depth is limited to 10".
            'max_pages_per_run' => 10,
            // The feed returns empty pages intermittently, so a short streak is
            // normal; three in a row means something is actually wrong.
            'max_consecutive_empty_pages' => 3,
            'timeout' => (int) env('APPSTORE_TIMEOUT', 15),
            'rate_limit' => ['max_attempts' => 20, 'decay_seconds' => 60],
            'retry_after' => 60,
        ],

    ],

];
