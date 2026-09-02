<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Echoes X-Correlation-Id when the caller supplies one, generates it otherwise,
 * and puts it on the log context so the same id appears on both sides of the
 * Laravel <-> FastAPI call (spec 3.6).
 */
class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header(self::HEADER);

        if (! is_string($correlationId) || trim($correlationId) === '' || strlen($correlationId) > 128) {
            $correlationId = (string) Str::uuid();
        }

        $request->headers->set(self::HEADER, $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
