<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Published for two reasons, one mild and one not.
|
| The mild one: the framework default is `allowed_origins => ['*']`. Nothing
| catastrophic followed from it - the credential is a bearer token in a header,
| not a cookie, and `supports_credentials` is false, so a hostile page could not
| ride an existing session - but "any origin may call this API" is not a
| decision anybody made, and it is the sort of default that becomes dangerous
| the day someone turns cookie authentication on.
|
| The one that mattered: the default `exposed_headers` is empty, and a browser
| hands JavaScript only the headers a cross-origin response names there. The SPA
| on :4200 calls the API on :8000, so `X-Quota-Remaining` was set by the backend,
| arrived in the response, and was invisible to `quotaInterceptor` - which read
| null on every single response. The usage meter appeared to work only because
| `/auth/me` carries `quota_remaining` in its body as a fallback. Jest could
| never see this: HttpTestingController has no CORS.
|
| `X-Correlation-Id` is exposed for the same reason on the diagnostic side: the
| id ties a browser report to the Laravel and FastAPI log lines (spec 3.6), and
| a support conversation cannot use an id the browser is not allowed to read.
|
*/

/**
 * The SPA origin, plus anything a deployment adds explicitly.
 *
 * `app.frontend_url` is already the address the verification and password-reset
 * mails link to, so origin and links cannot drift apart. Extra origins go in
 * CORS_ALLOWED_ORIGINS as a comma-separated list - a preview deployment, a
 * second brand - and never as a wildcard.
 *
 * @return list<string>
 */
$origins = static function (): array {
    $configured = array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ));

    $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:4200'), '/');

    return array_values(array_unique(array_filter([$frontend, ...$configured])));
};

return [

    // `api/*` covers /api/v1 and the webhook callbacks. The webhooks are called
    // server to server and never preflight, so listing them costs nothing and
    // leaving them out would mean two path lists to keep in step.
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins(),

    'allowed_origins_patterns' => [],

    // Request headers. The SPA sends Authorization, Content-Type, Accept,
    // Accept-Language and X-Correlation-Id; '*' here is about what the browser
    // may *send*, which the bearer token already gates on the server side.
    'allowed_headers' => ['*'],

    // Response headers JavaScript is allowed to read. Empty by default, which
    // is what hid X-Quota-Remaining from the SPA for the whole of wave 2.
    'exposed_headers' => ['X-Quota-Remaining', 'X-Correlation-Id', 'Retry-After'],

    // Cache the preflight for ten minutes. Zero means a second OPTIONS round
    // trip in front of every single mutation the SPA makes.
    'max_age' => 600,

    // No cookies, no CSRF surface. Authentication is a bearer token the SPA
    // holds; turning this on would make every allowed origin a session rider.
    'supports_credentials' => false,

];
