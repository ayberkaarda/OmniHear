<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs against the isolated test database', function () {
    // tests/bootstrap.php pins this to omnihear_test, except for the test_tmp_*
    // scratch databases parallel test runs get (CONTRIBUTING.md section 3). Asserting the
    // shared name only would turn every parallel run red for the wrong reason.
    $database = DB::connection()->getDatabaseName();
    $requested = getenv('DB_DATABASE');

    // Asserting only the shape of the name proved nothing: this test passed while
    // every workstream's DB_DATABASE was being silently overridden back to the shared
    // database by a <env force="true"> line in phpunit.xml that PHPUnit applies
    // *after* tests/bootstrap.php has run. Six parallel test runs across two rounds believed
    // they had their own database. Compare against what was actually asked for.
    if (is_string($requested) && $requested !== '') {
        expect($database)->toBe($requested);
    }

    expect($database === 'omnihear_test' || str_starts_with($database, 'test_tmp_'))->toBeTrue();
    expect(Schema::hasTable('users'))->toBeTrue();
});
