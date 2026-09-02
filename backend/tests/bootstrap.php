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
| that is guaranteed to win. CLAUDE.md Trap 4.
|
*/

$requested = getenv('DB_DATABASE');

// Parallel agents get their own scratch database (CLAUDE.md section 5); anything
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
