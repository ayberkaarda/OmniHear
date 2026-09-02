<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs against the isolated test database', function () {
    expect(DB::connection()->getDatabaseName())->toBe('omnihear_test');
    expect(Schema::hasTable('users'))->toBeTrue();
});
