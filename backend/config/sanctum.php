<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Sanctum
|--------------------------------------------------------------------------
|
| Published because of one line: `expiration`. The package ships it as `null`,
| and an unpublished config means the package default applies — so every
| personal access token this application had ever minted was valid forever. A
| bearer token that leaks into a log aggregator, a CI artifact or a screenshot
| stayed a working credential until somebody noticed and revoked it by hand.
|
| Everything else here is the package default, kept verbatim so a future
| `vendor:publish` diff is about our decisions and nothing else.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    | Unused in practice: this backend authenticates with bearer tokens and the
    | `api` middleware group never runs EnsureFrontendRequestsAreStateful, so no
    | request is ever upgraded to cookie authentication.
    |
    */

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | The absolute ceiling. A token older than this is refused by
    | Laravel\Sanctum\Guard whatever its own `expires_at` says, so it is the
    | value that actually bounds a leak, and it must stay >= the longest
    | per-token lifetime below or those tokens die early and silently.
    |
    | 90 days: long enough that a server-to-server key is a quarterly rotation
    | rather than a weekly outage, short enough that a credential which escaped
    | a year ago is already inert.
    |
    */

    'expiration' => (int) env('SANCTUM_EXPIRATION', 60 * 24 * 90),

    /*
    |--------------------------------------------------------------------------
    | Per-kind lifetimes (App\Support\Auth\TokenLifetime)
    |--------------------------------------------------------------------------
    |
    | Not Sanctum's own keys. A device session and an API key are the same row
    | in the same table (docs/contracts/settings-api.md section 3) but they leak
    | differently: a session lives in a browser on a laptop that gets lost, a
    | key lives in a CI configuration that gets forked. `expiration` above can
    | only express one number for both, so the distinction is made at mint time
    | through `expires_at`, which Sanctum checks in addition to the ceiling.
    |
    */

    'session_expiration' => (int) env('SANCTUM_SESSION_EXPIRATION', 60 * 24 * 14),

    'api_key_expiration' => (int) env('SANCTUM_API_KEY_EXPIRATION', 60 * 24 * 90),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => (string) env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
