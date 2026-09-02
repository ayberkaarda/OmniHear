<?php

use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\EnforceQuota;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\QuotaRemainingHeader;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantContext;
use App\Support\Http\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Broadcast channel authorization (spec 6.5). Registered explicitly rather
    // than through withRouting(channels: ...) because the default registration
    // puts /broadcasting/auth outside /api/v1 - and then a rejected
    // subscription answers with Laravel's own HTML-ish 403 instead of the
    // {code, message} envelope every other client-facing failure uses.
    // routes/channels.php itself defines who may listen.
    //
    // `verified` is listed explicitly. withBroadcasting() builds its own
    // middleware array and never sees the api group's defaults, so this route
    // was the one authenticated /api/v1 endpoint reachable without a verified
    // address - an unverified user could subscribe to their own company's
    // private channel and receive every FeedbackAnalyzed and
    // QuotaThresholdReached event on it. Spec 7.1 makes verification mandatory,
    // and EmailVerificationEnforcementTest no longer exempts this uri.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', 'verified']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Both are prepended, not appended. Laravel sorts route middleware by
        // $middlewarePriority, which puts Authenticate *before* SubstituteBindings
        // and therefore before anything appended to the api group: an appended
        // SetLocale would never run for a 401, and the error envelope would come
        // back in English however the client asked. Prepending also guarantees the
        // correlation id is stamped on every response, errors included.
        $middleware->prependToGroup('api', SetLocale::class);
        $middleware->prependToGroup('api', CorrelationId::class);

        // SetTenantContext must run before SubstituteBindings. Route-model
        // binding queries the model, CompanyScope needs a tenant, and without
        // this the scope throws MissingTenantContextException — so
        // GET /api/v1/feedbacks/{feedback} answered 500 instead of the 404 that
        // invariant I1 requires for another tenant's row. Ordering the route
        // middleware array is not enough: Laravel sorts by $middlewarePriority,
        // and anything absent from that list loses to anything in it. This is
        // the same sorting rule that put SetLocale behind Authenticate above.
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenantContext::class);

        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'quota.header' => QuotaRemainingHeader::class,
            // The paywall gate (spec 7.4). Applied per route, never to the whole
            // group: an exhausted quota must still be able to read the inbox and
            // the KPIs, because that is where the paywall is rendered.
            'quota' => EnforceQuota::class,
            'verified' => EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Single rendering path: every non-2xx /api/v1 response is {code, message}.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiErrorResponse::render($e, $request));
    })->create();
