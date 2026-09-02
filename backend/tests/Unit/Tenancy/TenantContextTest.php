<?php

use App\Support\Tenancy\TenantContext;

it('starts empty', function () {
    $tenant = new TenantContext;

    expect($tenant->has())->toBeFalse()
        ->and($tenant->id())->toBeNull();
});

it('sets and clears the tenant', function () {
    $tenant = new TenantContext;

    $tenant->set(7);
    expect($tenant->has())->toBeTrue()
        ->and($tenant->id())->toBe(7);

    $tenant->set(null);
    expect($tenant->has())->toBeFalse();
});

it('restores the previous tenant after runFor', function () {
    $tenant = new TenantContext;
    $tenant->set(1);

    $seen = $tenant->runFor(2, fn () => $tenant->id());

    expect($seen)->toBe(2)
        ->and($tenant->id())->toBe(1);
});

it('restores the previous tenant even when the callback throws', function () {
    $tenant = new TenantContext;
    $tenant->set(1);

    try {
        $tenant->runFor(2, function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($tenant->id())->toBe(1);
});

it('restores an empty context after runFor', function () {
    $tenant = new TenantContext;

    $tenant->runFor(5, fn () => null);

    expect($tenant->has())->toBeFalse();
});
