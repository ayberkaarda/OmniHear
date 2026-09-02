<?php

use App\Models\Company;
use App\Support\DisposableEmailDomains;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Spec 7.1 — "disposable/free domain blocklist", the second half
|--------------------------------------------------------------------------
|
| F2 shipped the disposable list only. A B2B product takes the corporate
| address as its signal that a real business is behind the sign-up, and a free
| consumer mailbox costs an abuser nothing to produce by the hundred.
|
*/

/**
 * Self-contained so this file does not depend on a helper declared in another
 * test file.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function corporateRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@acme-analytics.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'company_name' => 'Acme Inc.',
    ], $overrides);
}

beforeEach(function () {
    $this->blocklist = new DisposableEmailDomains;
});

it('refuses the major free providers', function (string $email) {
    expect($this->blocklist->blocksFreeProvider($email))->toBeTrue()
        ->and($this->blocklist->refuses($email))->toBeTrue();
})->with([
    'ada@gmail.com',
    'ada@GMAIL.COM',
    'ada@googlemail.com',
    'ada@outlook.com',
    'ada@hotmail.com',
    'ada@yahoo.com',
    'ada@icloud.com',
    'ada@proton.me',
    'ada@yandex.ru',
]);

it('accepts a corporate address', function (string $email) {
    expect($this->blocklist->refuses($email))->toBeFalse();
})->with([
    'ada@acme-analytics.com',
    'ada@omnihear.io',
    'ada@notgmail.com',
    'ada@gmail.company.com',
]);

it('keeps the two lists apart', function () {
    expect($this->blocklist->blocks('ada@gmail.com'))->toBeFalse()
        ->and($this->blocklist->blocksFreeProvider('ada@gmail.com'))->toBeTrue()
        ->and($this->blocklist->blocks('ada@mailinator.com'))->toBeTrue()
        ->and($this->blocklist->blocksFreeProvider('ada@mailinator.com'))->toBeFalse();
});

it('matches a subdomain of a listed free provider', function () {
    expect($this->blocklist->blocksFreeProvider('ada@mail.gmail.com'))->toBeTrue();
});

it('reads the free list from config', function () {
    config()->set('registration.free_domains', ['freebox.test']);

    expect($this->blocklist->blocksFreeProvider('ada@freebox.test'))->toBeTrue()
        ->and($this->blocklist->blocksFreeProvider('ada@gmail.com'))->toBeFalse();
});

it('can be switched off without touching the disposable list', function () {
    config()->set('registration.block_free_domains', false);

    expect($this->blocklist->blocksFreeProvider('ada@gmail.com'))->toBeFalse()
        ->and($this->blocklist->refuses('ada@gmail.com'))->toBeFalse()
        ->and($this->blocklist->refuses('ada@mailinator.com'))->toBeTrue();
});

it('does not choke on an address without a domain', function () {
    expect($this->blocklist->refuses('nonsense'))->toBeFalse()
        ->and($this->blocklist->refuses(''))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

it('refuses registration from a free provider with the existing code', function () {
    $this->postJson('/api/v1/auth/register', corporateRegistration(['email' => 'ada@gmail.com']))
        ->assertStatus(422)
        ->assertExactJson([
            'code' => 'DISPOSABLE_EMAIL',
            'message' => 'Disposable email addresses are not accepted. Use your work address.',
        ]);

    expect(Company::query()->count())->toBe(0);
});

it('still registers a corporate address', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', corporateRegistration())->assertCreated();

    expect(Company::query()->count())->toBe(1);
});

it('lets a deployment accept free providers by config', function () {
    Notification::fake();
    config()->set('registration.block_free_domains', false);

    $this->postJson('/api/v1/auth/register', corporateRegistration(['email' => 'ada@gmail.com']))
        ->assertCreated();

    expect(Company::query()->count())->toBe(1);
});
