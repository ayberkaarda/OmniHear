<?php

use App\Support\Http\ApiErrorCode;
use Illuminate\Support\Facades\App;

it('has an English message for every code', function () {
    foreach (ApiErrorCode::cases() as $case) {
        App::setLocale('en');

        expect($case->message())
            ->not->toBe('errors.'.$case->value)
            ->not->toBeEmpty();
    }
});

it('has a Turkish message for every code', function () {
    $en = require lang_path('en/errors.php');
    $tr = require lang_path('tr/errors.php');

    expect(array_keys($tr))->toBe(array_keys($en));

    foreach (ApiErrorCode::cases() as $case) {
        App::setLocale('tr');

        expect($case->message())
            ->not->toBe('errors.'.$case->value)
            ->not->toBe($en[$case->value]);
    }
});

it('keeps the message catalogue keys in step across locales', function (string $file) {
    $en = require lang_path("en/{$file}.php");
    $tr = require lang_path("tr/{$file}.php");

    expect(array_keys($tr))->toBe(array_keys($en));
})->with(['errors', 'messages']);
