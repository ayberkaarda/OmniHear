<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side localisation of API messages when the client asks for it via
 * Accept-Language (contract section 2). Anything unsupported falls back to the
 * configured application locale.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = (array) config('app.supported_locales', ['en']);

        $locale = $request->getPreferredLanguage($supported);

        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
