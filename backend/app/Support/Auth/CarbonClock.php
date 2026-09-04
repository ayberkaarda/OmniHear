<?php

namespace App\Support\Auth;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;

/**
 * A PSR-20 clock that reads Laravel's Carbon.
 *
 * `OTPHP\TOTP` takes an optional clock and, when none is given, emits a
 * deprecation (mandatory in otphp 12) and installs its own `InternalClock`,
 * which reads the system time and is therefore deaf to `Carbon::setTestNow()`.
 *
 * `Totp` passes every timestamp explicitly, so today the library's clock is
 * consulted only by the methods this codebase does not call — `now()` and
 * `expiresIn()`. Injecting it anyway is what keeps that a fact about the
 * *caller* rather than a coincidence: the day something reaches for one of
 * those, it reads the same "now" the rest of the application does instead of
 * silently disagreeing with `Carbon::setTestNow()` in the tests that pin it.
 */
final class CarbonClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }
}
