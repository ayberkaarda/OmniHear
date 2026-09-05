<?php

/*
|--------------------------------------------------------------------------
| Test bootstrap — environment pinning
|--------------------------------------------------------------------------
|
| The dev compose stack injects backend/.env into the container with
| `env_file:`, so DB_DATABASE=omnihear (the *development* database),
| CACHE_STORE=redis and QUEUE_CONNECTION=redis already exist as real
| environment variables before PHPUnit starts.
|
| PHPUnit's <env force="true"> is not enough on its own: it rewrites the
| putenv() layer, while Laravel's Env reader consults the $_SERVER / $_ENV
| adapters first. The override loses silently, and the consequences are not
| subtle — RefreshDatabase runs `migrate:fresh` against the development
| database, and the rate limiter writes into the Redis instance that holds the
| Horizon queue. Both happened here on 2026-09-02, before this file existed.
|
| Overwriting all three layers before the autoloader runs is the only point
| that is guaranteed to win. CONTRIBUTING.md Trap 4.
|
*/

/*
|--------------------------------------------------------------------------
| Memory
|--------------------------------------------------------------------------
|
| The container's php.ini leaves memory_limit at 128M, which the suite itself
| fits inside comfortably — but php-code-coverage serializes the whole coverage
| object at the end of the run, and that step crossed 128M once the suite passed
| ~950 tests: `php artisan test --coverage --min=80` died with
|
|   Allowed memory size of 134217728 bytes exhausted
|   in vendor/phpunit/php-code-coverage/src/Report/PHP.php
|
| *after* printing "Tests: … passed", so the run looked green and the gate's
| coverage number silently never appeared.
|
| Raised here rather than in infra/: `php artisan test` spawns the Pest process,
| which does not inherit a `-d memory_limit` given to artisan, and this file is
| the first thing that process runs. Only raised, never lowered, so a runner
| that already has more (or no limit) keeps it.
|
*/

$currentLimit = trim((string) ini_get('memory_limit'));

if ($currentLimit !== '-1' && $currentLimit !== '') {
    $unit = strtolower(substr($currentLimit, -1));
    $bytes = (int) $currentLimit * match ($unit) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };

    if ($bytes < 1024 ** 3) {
        ini_set('memory_limit', '1G');
    }
}

$requested = getenv('DB_DATABASE');

// Parallel test runs get their own scratch database (CONTRIBUTING.md section 3); anything
// else is forced onto the shared test database, never onto a dev database.
$database = is_string($requested) && str_starts_with($requested, 'test_tmp_')
    ? $requested
    : 'omnihear_test';

$overrides = [
    'APP_ENV' => 'testing',
    // Derived, not a literal: the suite must not depend on a .env that is
    // gitignored. Without a key the encrypted casts throw MissingAppKeyException
    // and every test that touches integrations.credentials — the mechanism
    // invariant I5 rests on — fails on any clean checkout. CI proved that the
    // first time it ran.
    'APP_KEY' => 'base64:'.base64_encode('omnihear-testing-key-not-secret!'),
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => $database,
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
];

foreach ($overrides as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
