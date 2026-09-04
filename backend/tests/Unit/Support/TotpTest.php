<?php

use App\Support\Auth\CarbonClock;
use App\Support\Auth\Totp;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| RFC 6238 Appendix B
|--------------------------------------------------------------------------
|
| The expected values below are not computed here. They are the published test
| vectors, copied from the RFC, for the shared secret it names:
|
|   ASCII  "12345678901234567890"   (20 bytes)
|   base32 "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"
|
| That is the whole point of this file. A test that asks Totp for a code and
| then asks Totp whether that code verifies proves only that the class agrees
| with itself: it stays green if the HMAC key is byte-reversed, if the counter
| is off by one, if the base32 alphabet is wrong — every one of which produces a
| self-consistent generator that no authenticator app on earth can read. Only an
| externally published number can tell the difference, and interoperability with
| Google Authenticator, 1Password and Authy is the entire feature.
|
| The RFC tabulates eight digits; this application uses six. Those are the same
| number: HOTP truncates modulo 10^Digits, so the six-digit code is the last six
| digits of the eight-digit one. Both are written out below so the derivation is
| visible rather than asserted.
|
| The clock is pinned with Carbon::setTestNow, which Totp reads directly. otphp
| would otherwise install a system-time clock of its own, so CarbonClock is
| handed to it as well and is asserted separately below.
|
*/

const RFC6238_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

afterEach(function () {
    Carbon::setTestNow();
});

it('produces the published RFC 6238 code at the published time', function (int $timestamp, string $eightDigits, string $expected) {
    expect(substr($eightDigits, -6))->toBe($expected, 'the vector was transcribed wrongly')
        ->and(Totp::codeAt(RFC6238_SECRET, $timestamp))->toBe($expected);
})->with([
    'T1 1970-01-01 00:00:59' => [59, '94287082', '287082'],
    'T2 2005-03-18 01:58:29' => [1111111109, '07081804', '081804'],
    'T3 2005-03-18 01:58:31' => [1111111111, '14050471', '050471'],
    'T4 2009-02-13 23:31:30' => [1234567890, '89005924', '005924'],
    'T5 2033-05-18 03:33:20' => [2000000000, '69279037', '279037'],
    'T6 2603-10-11 11:33:20' => [20000000000, '65353130', '353130'],
]);

it('verifies the published code at the published time', function (int $timestamp, string $expected) {
    Carbon::setTestNow(Carbon::createFromTimestampUTC($timestamp));

    expect(Totp::verify(RFC6238_SECRET, $expected))->toBe(Totp::timestep($timestamp));
})->with([
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
]);

it('reads the clock the application reads rather than the system one', function () {
    // Totp takes its "now" from Carbon rather than from the system clock, which
    // is the whole reason the vectors above can be asserted at a fixed instant.
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1111111109));

    expect(Totp::codeAt(RFC6238_SECRET, Carbon::now()->getTimestamp()))->toBe('081804')
        ->and(Totp::verify(RFC6238_SECRET, '081804'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The window boundary
|--------------------------------------------------------------------------
|
| A separate test from correctness on purpose: "the code is right" and "how far
| either side of now we still accept it" are independent decisions, and a single
| test that mixes them cannot say which one broke.
|
| 1111111109 falls in step 37037036 (1111111109 / 30 = 37037036.96), so the
| neighbours are the steps at 1111111079 and 1111111139.
|
*/

it('accepts exactly one step either side of the current one', function (int $offset, bool $accepted) {
    $now = 1111111109;
    Carbon::setTestNow(Carbon::createFromTimestampUTC($now));

    $step = Totp::timestep($now) + $offset;
    $code = Totp::codeAtStep(RFC6238_SECRET, $step);

    expect(Totp::verify(RFC6238_SECRET, $code))->toBe($accepted ? $step : null);
})->with([
    'two steps behind' => [-2, false],
    'one step behind' => [-1, true],
    'the current step' => [0, true],
    'one step ahead' => [1, true],
    'two steps ahead' => [2, false],
]);

it('refuses anything that is not six digits', function (string $code) {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1111111109));

    expect(Totp::verify(RFC6238_SECRET, $code))->toBeNull();
})->with([
    'empty' => [''],
    'five digits' => ['08180'],
    'seven digits' => ['0818040'],
    'letters' => ['abcdef'],
    'the eight-digit form of the right code' => ['07081804'],
]);

/*
|--------------------------------------------------------------------------
| Replay
|--------------------------------------------------------------------------
|
| Also separate. The window says a code is arithmetically valid; this says it is
| only *usable* once, which is a different property and the one that makes a
| shoulder-surfed code worthless the moment it has been spent.
|
*/

it('refuses a step that has already been spent, and everything below it', function () {
    $now = 1111111109;
    Carbon::setTestNow(Carbon::createFromTimestampUTC($now));

    $current = Totp::timestep($now);
    $code = Totp::codeAtStep(RFC6238_SECRET, $current);

    // The first use is fine and reports the step it consumed.
    expect(Totp::verify(RFC6238_SECRET, $code))->toBe($current);

    // The same six digits, still inside their own window, now refused.
    expect(Totp::verify(RFC6238_SECRET, $code, notAtOrBelow: $current))->toBeNull();

    // And so is the previous step, which the ±1 window would otherwise still
    // accept: a replay attacker holding an older code must not be let in by
    // the drift allowance.
    $previous = Totp::codeAtStep(RFC6238_SECRET, $current - 1);

    expect(Totp::verify(RFC6238_SECRET, $previous))->toBe($current - 1)
        ->and(Totp::verify(RFC6238_SECRET, $previous, notAtOrBelow: $current))->toBeNull();

    // The next step is not spent and is still accepted.
    $next = Totp::codeAtStep(RFC6238_SECRET, $current + 1);

    expect(Totp::verify(RFC6238_SECRET, $next, notAtOrBelow: $current))->toBe($current + 1);
});

/*
|--------------------------------------------------------------------------
| Secrets and provisioning
|--------------------------------------------------------------------------
*/

it('generates a 160-bit base32 secret', function () {
    $secret = Totp::generateSecret();

    // RFC 4226 section 4 requires at least 128 bits and recommends 160.
    // 20 bytes of base32 is 32 characters with no padding.
    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and(Totp::generateSecret())->not->toBe($secret);
});

it('hands otphp a clock that reads Carbon rather than the system time', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1111111109));

    expect((new CarbonClock)->now()->getTimestamp())->toBe(1111111109);

    Carbon::setTestNow();
});

it('builds a provisioning uri an authenticator app can read', function () {
    $uri = Totp::provisioningUri(RFC6238_SECRET, 'ada@acme-analytics.com', 'OmniHear');

    expect($uri)->toStartWith('otpauth://totp/')
        ->and($uri)->toContain('secret='.RFC6238_SECRET)
        ->and($uri)->toContain('issuer=OmniHear')
        ->and($uri)->toContain(rawurlencode('ada@acme-analytics.com'));
});
