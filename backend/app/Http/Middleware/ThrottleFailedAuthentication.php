<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Http\ApiErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * A per-IP ceiling on *failed* authentication, in front of the whole API.
 *
 * # Why `throttle:api` is not this
 *
 * `routes/api.php` lists `throttle:api` inside the authenticated stack, which
 * reads as if an anonymous caller were limited. It is not. Laravel sorts route
 * middleware by `$middlewarePriority`, where `AuthenticatesRequests` precedes
 * `ThrottleRequests` (Illuminate\Foundation\Http\Kernel), so `auth:sanctum`
 * always runs first and a request with a missing or invalid token answers 401
 * before the limiter is ever reached. Every `/api/v1/*` route could therefore
 * be hit without limit, each attempt paying for a `personal_access_tokens`
 * lookup.
 *
 * Adding a second `throttle:` to the front of that array does not help: both
 * entries are the same class, so priority sorting moves both behind
 * `Authenticate`. This is the third time `$middlewarePriority` has bitten this
 * codebase - docs/LESSONS.md has the other two. The shape that works is a
 * middleware **absent** from the priority list, prepended to the `api` group
 * the way CorrelationId is, which is what this is.
 *
 * # Why it counts failures rather than requests
 *
 * A plain per-IP request ceiling would be simpler and would also be wrong here:
 * the SPA and the API are separate origins, one office or one mobile carrier
 * NAT can put dozens of legitimate tenants behind a single address, and a
 * ceiling low enough to matter against an attacker is low enough to break them.
 *
 * So the budget is spent only by responses that came back 401. Legitimate
 * traffic - authenticated, or a public route answering 200/422 - never touches
 * it, and an attacker walking token space exhausts it in seconds. The exception
 * an inner middleware throws has already been rendered into a response by
 * Illuminate\Routing\Pipeline by the time it returns here, so the status is
 * readable without catching anything.
 *
 * The remaining cost is real and deliberate: once an address has tripped the
 * limit, every request from it is refused for the decay window, including a
 * legitimate one sharing that NAT. That is the standard trade of any IP-keyed
 * defence, and the failure-only accounting is what keeps the window from being
 * entered by honest traffic in the first place.
 *
 * `/api/v1/auth/login` and `/api/v1/auth/register` keep their own, much
 * tighter, per-IP limiters (10/min and 5/hour): those bound password guessing,
 * which is a different thing from bearer-token guessing and needs the smaller
 * number.
 */
class ThrottleFailedAuthentication
{
    public const KEY_PREFIX = 'api-auth-failed:';

    public function handle(Request $request, Closure $next): Response
    {
        $key = self::KEY_PREFIX.$request->ip();
        $max = (int) config('auth.failed_authentication.max_attempts', 60);
        $decay = (int) config('auth.failed_authentication.decay_minutes', 1);

        if ($max > 0 && RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiException(
                ApiErrorCode::TooManyRequests,
                retryAfter: RateLimiter::availableIn($key),
            );
        }

        $response = $next($request);

        if ($max > 0 && $response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
            RateLimiter::hit($key, $decay * 60);
        }

        return $response;
    }
}
