<?php

use App\Support\DisposableEmailDomains;

beforeEach(function () {
    $this->blocklist = new DisposableEmailDomains;
});

it('blocks a listed domain', function (string $email) {
    expect($this->blocklist->blocks($email))->toBeTrue();
})->with([
    'ada@mailinator.com',
    'ada@MAILINATOR.COM',
    'ada@mail.mailinator.com',
    'ada@yopmail.com',
]);

it('allows a normal domain', function (string $email) {
    expect($this->blocklist->blocks($email))->toBeFalse();
})->with([
    'ada@acme-analytics.com',
    'ada@gmail.com',
    'ada@notmailinator.com',
]);

it('does not choke on an address without a domain', function () {
    expect($this->blocklist->blocks('nonsense'))->toBeFalse()
        ->and($this->blocklist->blocks(''))->toBeFalse();
});

it('reads the list from config', function () {
    config()->set('registration.disposable_domains', ['blocked.test']);

    expect($this->blocklist->blocks('ada@blocked.test'))->toBeTrue()
        ->and($this->blocklist->blocks('ada@mailinator.com'))->toBeFalse();
});
