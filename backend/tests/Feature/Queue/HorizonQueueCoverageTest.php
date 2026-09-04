<?php

use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;

/*
|--------------------------------------------------------------------------
| config/horizon.php — queue coverage and environment matching
|--------------------------------------------------------------------------
|
| What this proves: the *shape* of backend/config/horizon.php - every
| supervisor Horizon would provision, in every declared environment, watches
| both the 'default' queue and config('ai.queue'), and the environment key
| the dev compose stack actually runs under (APP_ENV=local, per
| backend/.env.example and infra/docker-compose.dev.yml, which sets no
| APP_ENV override) is one Horizon's own matching rule would select.
|
| What this does NOT prove: that a real Horizon worker drains
| omnihear_database_queues:analysis, that AnalyzeFeedbackJob actually runs, or
| that analyzed_feedback_count changes. backend/tests/bootstrap.php forces
| QUEUE_CONNECTION=sync and APP_ENV=testing for the whole suite, and
| `php artisan horizon` is never invoked here - so
| ProvisioningPlan::deploy(), the method that actually spawns workers, is
| never exercised by this file. That end-to-end path can only be measured
| against the running docker compose stack (see docs/LESSONS.md for the
| earlier measurement that found the defect this config fixes).
|
*/

it('has every supervisor, in every declared environment, watch both the default and the analysis queue', function () {
    // ProvisioningPlan::get() runs the real merge Horizon uses in production —
    // array_replace_recursive('defaults', environment overrides) — so this
    // reads the config the way Horizon itself would, not a reimplementation
    // of its merge logic that could drift from the real one.
    $plan = ProvisioningPlan::get('horizon');

    $analysisQueue = config('ai.queue');

    // Guards config/ai.php and config/horizon.php from drifting apart: if the
    // reference in config/horizon.php's 'defaults' ever breaks (e.g. a config
    // file rename that changes load order), config('ai.queue') read here,
    // independently, would still be a real value and expose the mismatch.
    expect($analysisQueue)->toBeString()->not->toBeEmpty();
    expect($plan->parsed)->not->toBeEmpty();

    foreach ($plan->parsed as $environment => $supervisors) {
        foreach ($supervisors as $supervisorName => $options) {
            $queues = explode(',', (string) $options->queue);

            expect($queues)
                ->toContain('default')
                ->toContain($analysisQueue);
        }
    }
});

it("matches APP_ENV=local under Horizon's own Str::is() rule, the way infra/docker-compose.dev.yml runs it", function () {
    $environments = array_keys(config('horizon.environments'));

    // Str::is(), not array_key_exists() or in_array(): ProvisioningPlan::deploy()
    // resolves the environment this way. A wildcard key such as '*' would
    // satisfy in_array('local', $environments) === false while still being
    // the entry that actually deploys under APP_ENV=local — so only Str::is()
    // proves what production checks.
    $matched = collect($environments)->first(fn (string $pattern) => Str::is($pattern, 'local'));

    expect($matched)->not->toBeNull();
});

it('never leaves a declared environment with zero live supervisors', function () {
    // The exact failure mode this file exists to close: ProvisioningPlan::deploy()
    // finds no matching environment (or a matching one with maxProcesses <= 0)
    // and silently provisions nothing, while `horizon:status` still reports
    // "running". See ProvisioningPlan::deploy(), vendor/laravel/horizon/src/ProvisioningPlan.php:105-116.
    $plan = ProvisioningPlan::get('horizon');

    foreach ($plan->parsed as $environment => $supervisors) {
        $liveSupervisors = collect($supervisors)->filter(fn ($options) => $options->maxProcesses > 0);

        expect($liveSupervisors)->not->toBeEmpty();
    }
});
