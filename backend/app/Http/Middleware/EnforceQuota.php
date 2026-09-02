<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Http\ApiErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTTP face of the paywall (spec 7.4): 402 QUOTA_EXCEEDED on any route that
 * would consume analysis quota.
 *
 * Registered as the `quota` alias in bootstrap/app.php. It is deliberately NOT
 * on the whole authenticated surface: when the quota runs out the SPA still has
 * to read the inbox, the KPIs and the billing page in order to *show* the
 * paywall. Only routes that would spend a unit carry it - the trigger that asks
 * a connector for more feedback being the obvious one.
 *
 * The 402 body is the catalogue envelope, `{code, message}`, exactly as
 * docs/contracts/http-api-v1.md section 2 fixes it for every non-2xx response.
 * The remaining balance travels in the X-Quota-Remaining header that
 * QuotaRemainingHeader puts on every authenticated response, so the paywall
 * modal has the number without the envelope having to grow a special case.
 */
class EnforceQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->user()?->company;

        if ($company !== null && $company->quotaRemaining() <= 0) {
            throw new ApiException(ApiErrorCode::QuotaExceeded);
        }

        return $next($request);
    }
}
