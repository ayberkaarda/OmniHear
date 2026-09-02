<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API flavour of Laravel's `verified` middleware: it raises the catalogue
 * code EMAIL_NOT_VERIFIED instead of a bare 403, so the SPA can route the user
 * to the "check your inbox" screen.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            throw ApiException::emailNotVerified();
        }

        return $next($request);
    }
}
