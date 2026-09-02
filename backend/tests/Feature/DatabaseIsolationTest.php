<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs against the isolated test database', function () {
    // tests/bootstrap.php pins this to omnihear_test, except for the test_tmp_*
    // scratch databases parallel agents get (CLAUDE.md section 5). Asserting the
    // shared name only would turn every parallel run red for the wrong reason.
    $database = DB::connection()->getDatabaseName();

    expect($database === 'omnihear_test' || str_starts_with($database, 'test_tmp_'))->toBeTrue();
    expect(Schema::hasTable('users'))->toBeTrue();
});
