<?php

namespace App\Support\Auth;

use Illuminate\Support\Carbon;
use OTPHP\TOTP as Generator;

/**
 * RFC 6238 time-based one-time passwords (docs/contracts/w10-two-factor.md).
 *
 * A thin, deliberate wrapper over spomky-labs/otphp rather than a re-export of
 * it, for three reasons the endpoints actually depend on:
 *
 *  1. **Time is an argument, not an ambient.** Every method here takes the
 *     timestamp it works from, or reads it from Carbon — never from the
 *     library, which installs a system-time `InternalClock` that cannot hear
 *     `Carbon::setTestNow()`. That is what lets the RFC 6238 Appendix B
 *     vectors, which are nothing but pinned timestamps, be asserted at all.
 *     CarbonClock is passed in as well so the library agrees with Carbon on the
 *     paths this class does not drive.
 *
 *  2. **`verify()` cannot express the window we need.** Its `$leeway` is in
 *     *seconds* and must be strictly smaller than the period, so a ±1
 *     *timestep* window (the interoperable default: one step behind for a slow
 *     user, one ahead for a fast phone) is not expressible with it at all.
 *     Here the window is counted in steps and checked step by step.
 *
 *  3. **A boolean is not enough.** Replay rejection needs to know *which* step
 *     was accepted, so the caller can refuse that step and everything below it
 *     the next time. `verify()` throws that away.
 *
 * Every comparison is `hash_equals`: the codes are short and a timing oracle on
 * six digits is not a theoretical concern.
 */
final class Totp
{
    /** Seconds per timestep. The interoperable value; authenticator apps assume it. */
    public const PERIOD = 30;

    public const DIGITS = 6;

    /**
     * Steps of drift accepted either side of the current one.
     *
     * One step, not more: at 30 seconds a code is already valid for up to 90
     * seconds with this window, and each extra step multiplies an online
     * guessing attacker's odds by the same factor it buys a user with a slow
     * thumb.
     */
    public const WINDOW = 1;

    /**
     * A fresh base32 secret, 160 bits — the size RFC 4226 section 4 requires.
     */
    public static function generateSecret(): string
    {
        return Generator::generate(new CarbonClock, secretSize: 20)->getSecret();
    }

    /**
     * The timestep a unix timestamp falls in.
     */
    public static function timestep(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    /**
     * The code for a given timestep.
     */
    public static function codeAtStep(string $secret, int $step): string
    {
        return self::generator($secret)->at($step * self::PERIOD);
    }

    /**
     * The code that is valid at a unix timestamp.
     */
    public static function codeAt(string $secret, int $timestamp): string
    {
        return self::generator($secret)->at($timestamp);
    }

    /**
     * The timestep the submitted code belongs to, or null when it belongs to
     * none inside the window.
     *
     * `$notAtOrBelow` is the replay guard: a step that has already been spent
     * is refused even though the arithmetic still matches, because accepting
     * the same six digits twice inside their window is exactly the replay a
     * second factor exists to prevent.
     */
    public static function verify(string $secret, string $code, ?int $notAtOrBelow = null, ?int $timestamp = null): ?int
    {
        $code = trim($code);

        if ($secret === '' || ! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $timestamp ??= Carbon::now()->getTimestamp();
        $current = self::timestep($timestamp);
        $generator = self::generator($secret);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;

            if ($step < 0) {
                continue;
            }

            if (! hash_equals($generator->at($step * self::PERIOD), $code)) {
                continue;
            }

            return $notAtOrBelow !== null && $step <= $notAtOrBelow ? null : $step;
        }

        return null;
    }

    /**
     * The `otpauth://` URI an authenticator app scans.
     *
     * The label is the account the code belongs to and the issuer is what the
     * app shows above it; both are set explicitly so a user with several
     * accounts can tell the entries apart.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $generator = self::generator($secret);
        $generator->setLabel($account);
        $generator->setIssuer($issuer);

        return $generator->getProvisioningUri();
    }

    private static function generator(string $secret): Generator
    {
        return Generator::createFromSecret($secret, new CarbonClock);
    }
}
