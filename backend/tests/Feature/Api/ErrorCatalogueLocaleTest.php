<?php

use App\Support\Http\ApiErrorCode;

/*
 * Lives in Feature, not Unit: resolving a translation needs a booted
 * application, and tests/Pest.php only binds TestCase to the Feature suite.
 *
 * A missing translation does not throw in Laravel — __() returns the key
 * itself. So an untranslated code would reach the SPA as the literal string
 * "errors.QUOTA_EXCEEDED", which is exactly the raw-server-string leak the
 * frontend contract forbids. Nothing else in the suite catches that.
 */
it('has a message in every supported locale for every catalogue code', function () {
    $locales = config('app.supported_locales');

    expect($locales)->not->toBeEmpty();

    foreach ($locales as $locale) {
        app()->setLocale($locale);

        foreach (ApiErrorCode::cases() as $code) {
            expect($code->message())
                ->not->toBe('errors.'.$code->value, "missing {$locale} message for {$code->value}")
                ->not->toBe('');
        }
    }
});
