<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| CompanyPolicy
|--------------------------------------------------------------------------
*/

it('lets any role view its own company', function (string $role) {
    [$company, $user] = tenant($role);

    expect(Gate::forUser($user)->allows('view', $company))->toBeTrue();
})->with([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER]);

it('lets owners and admins update the company but not members', function (string $role, bool $allowed) {
    [$company, $user] = tenant($role);

    expect(Gate::forUser($user)->allows('update', $company))->toBe($allowed);
})->with([
    [User::ROLE_OWNER, true],
    [User::ROLE_ADMIN, true],
    [User::ROLE_MEMBER, false],
]);

it('lets only the owner delete the company', function (string $role, bool $allowed) {
    [$company, $user] = tenant($role);

    expect(Gate::forUser($user)->allows('delete', $company))->toBe($allowed);
})->with([
    [User::ROLE_OWNER, true],
    [User::ROLE_ADMIN, false],
    [User::ROLE_MEMBER, false],
]);

it('denies every company action across tenants', function (string $ability) {
    [$companyA, $userA] = tenant();
    $companyB = Company::factory()->create();

    expect(Gate::forUser($userA)->allows($ability, $companyB))->toBeFalse();

    $response = Gate::forUser($userA)->inspect($ability, $companyB);
    expect($response->status())->toBe(404);
})->with(['view', 'update', 'delete']);

/*
|--------------------------------------------------------------------------
| UserPolicy
|--------------------------------------------------------------------------
*/

it('lets any role list the team', function (string $role) {
    [$company, $user] = tenant($role);

    expect(Gate::forUser($user)->allows('viewAny', User::class))->toBeTrue();
})->with([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER]);

it('lets owners and admins invite teammates', function (string $role, bool $allowed) {
    [$company, $user] = tenant($role);

    expect(Gate::forUser($user)->allows('create', User::class))->toBe($allowed);
})->with([
    [User::ROLE_OWNER, true],
    [User::ROLE_ADMIN, true],
    [User::ROLE_MEMBER, false],
]);

it('lets a member update itself but not a teammate', function () {
    [$company, $member] = tenant(User::ROLE_MEMBER);
    $teammate = User::factory()->for($company)->create();

    expect(Gate::forUser($member)->allows('update', $member))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $teammate))->toBeFalse();
});

it('lets only the owner delete a teammate, and never itself', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $admin = User::factory()->for($company)->admin()->create();
    $member = User::factory()->for($company)->member()->create();

    expect(Gate::forUser($owner)->allows('delete', $member))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $owner))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $member))->toBeFalse();
});

it('denies every user action across tenants as not found', function (string $ability) {
    [$companyA, $userA] = tenant();
    $userB = User::factory()->create();

    $response = Gate::forUser($userA)->inspect($ability, $userB);

    expect($response->allowed())->toBeFalse()
        ->and($response->status())->toBe(404);
})->with(['view', 'update', 'delete']);

/*
|--------------------------------------------------------------------------
| Role gates
|--------------------------------------------------------------------------
*/

it('grants the role gates hierarchically', function (string $role, bool $owner, bool $admin, bool $member) {
    [$company, $user] = tenant($role);

    expect(Gate::forUser($user)->allows('act-as-owner'))->toBe($owner)
        ->and(Gate::forUser($user)->allows('act-as-admin'))->toBe($admin)
        ->and(Gate::forUser($user)->allows('act-as-member'))->toBe($member);
})->with([
    [User::ROLE_OWNER, true, true, true],
    [User::ROLE_ADMIN, false, true, true],
    [User::ROLE_MEMBER, false, false, true],
]);

it('exposes the role helpers on the user model', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    [, $admin] = tenant(User::ROLE_ADMIN);
    [, $member] = tenant(User::ROLE_MEMBER);

    expect($owner->isOwner())->toBeTrue()
        ->and($owner->isAdmin())->toBeFalse()
        ->and($admin->isAdmin())->toBeTrue()
        ->and($member->isMember())->toBeTrue()
        ->and($member->isOwner())->toBeFalse()
        ->and($member->hasRole(User::ROLE_OWNER, User::ROLE_MEMBER))->toBeTrue();
});

it('reports two factor as disabled until a secret is stored', function () {
    [$company, $user] = tenant();

    expect($user->twoFactorEnabled())->toBeFalse();

    $user->forceFill(['two_factor_secret' => 'ABC123'])->save();

    expect(User::query()->findOrFail($user->id)->twoFactorEnabled())->toBeTrue();
});

it('encrypts the two factor secret at rest', function () {
    [$company, $user] = tenant();
    $user->forceFill(['two_factor_secret' => 'TOTPSECRET'])->save();

    // tenant-scope: bypass-ok asserting the raw column bytes
    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($raw)->not->toContain('TOTPSECRET')
        ->and(User::query()->findOrFail($user->id)->two_factor_secret)->toBe('TOTPSECRET');
});
