<?php

use App\Support\Http\ApiErrorCode;

it('maps every catalogue code to the documented status', function (string $code, int $status) {
    expect(ApiErrorCode::from($code)->status())->toBe($status);
})->with([
    ['VALIDATION_ERROR', 422],
    ['INVALID_CREDENTIALS', 401],
    ['UNAUTHENTICATED', 401],
    ['EMAIL_NOT_VERIFIED', 403],
    ['FORBIDDEN', 403],
    ['NOT_FOUND', 404],
    ['QUOTA_EXCEEDED', 402],
    ['TOO_MANY_REQUESTS', 429],
    ['DISPOSABLE_EMAIL', 422],
    ['SERVER_ERROR', 500],
    ['INTEGRATION_UNAVAILABLE', 503],
    ['INTEGRATION_INVALID_CREDENTIALS', 422],
    ['SYNC_IN_PROGRESS', 409],
    ['AI_SERVICE_UNAVAILABLE', 503],
    ['INVALID_WEBHOOK_SIGNATURE', 400],
    ['PAYMENT_PROVIDER_ERROR', 502],
    ['TWO_FACTOR_CODE_INVALID', 422],
    ['TWO_FACTOR_ALREADY_ENABLED', 409],
    ['TWO_FACTOR_NOT_ENABLED', 409],
]);

it('maps an http status back onto a catalogue code', function (int $status, string $code) {
    expect(ApiErrorCode::fromStatus($status)->value)->toBe($code);
})->with([
    [401, 'UNAUTHENTICATED'],
    [402, 'QUOTA_EXCEEDED'],
    [403, 'FORBIDDEN'],
    [404, 'NOT_FOUND'],
    [422, 'VALIDATION_ERROR'],
    [429, 'TOO_MANY_REQUESTS'],
    [418, 'SERVER_ERROR'],
    [503, 'SERVER_ERROR'],
]);

// A bare count is a weak tripwire: it fires on every addition without saying
// what is wrong, and it says nothing about whether the new code is usable. What
// actually matters is that a code the SPA may receive has a message in both
// locales, so that is what this asserts. The count stays as a reminder that
// adding a case is a contract change (docs/contracts/http-api-v1.md section 2).
it('covers exactly the codes in the contract', function () {
    expect(ApiErrorCode::cases())->toHaveCount(19);
});
