<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Auth\TokenAbility;
use App\Support\Http\ApiErrorCode;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an API key is allowed to do (docs/contracts/settings-api.md section 3).
 *
 * # The hole this closes
 *
 * `/settings/api-keys` mints a credential the user is told to paste into a CI
 * runner. Until this middleware existed the ability list on that token was
 * decorative: it separated the two *listing* screens and nothing else, so a
 * leaked key could mint further keys, revoke the owner's device sessions,
 * change the account e-mail, start a checkout, and call
 * `DELETE /api/v1/account`. A machine credential could destroy the tenant.
 *
 * # Default deny
 *
 * The rule is not a list of forbidden routes but a list of permitted ones:
 * an API key may **read the tenant's feedback data and drive ingestion**, and
 * nothing else. Anything that changes the tenant, its people, its money or its
 * credentials is session-only, and so is every route added in the future,
 * because a route nobody has classified is denied by construction.
 *
 * `AuthorizedRoutesTest` keeps the list honest from both ends: every
 * authenticated route is classified, and every name here still resolves to a
 * route.
 *
 * # Why not Sanctum's `abilities:` / `ability:` middleware
 *
 * Both go through `PersonalAccessToken::can()`, and `can()` answers *true* for
 * every ability on a legacy `['*']` token. Sanctum's middleware would therefore
 * promote every token minted before the distinction existed into a full API
 * key — the exact inversion of `TokenAbility`, which deliberately treats `['*']`
 * as a session. The question asked here is `TokenAbility::isApiKey()`, matched
 * on the literal `api` ability, and never `can()`.
 *
 * # What counts as "not an API key"
 *
 * Only a persisted `PersonalAccessToken` carrying the literal `api` ability is
 * one. Everything else passes: a `TransientToken` (Sanctum's stand-in for a
 * first-party session, and what `actingAs($user, 'sanctum')` leaves behind), a
 * legacy wildcard token, and the null token of a request that has not been
 * authenticated at all — that last case belongs to `auth:sanctum`, which
 * answers 401 a step later, and pre-empting it here would turn a missing
 * credential into a 403.
 */
class EnforceTokenAbility
{
    /**
     * The routes an API key may reach. Everything else is session-only.
     *
     * Read-only tenant data plus the one write a machine integration genuinely
     * needs — triggering a sync. `auth.me` is here because any client has to be
     * able to ask who it is and how much quota is left; `auth.logout` because
     * revoking the credential you are holding must never require a browser.
     *
     * Deliberately absent, with the reason:
     *
     *  - `account.destroy` — erasure of the whole tenant.
     *  - `auth.tokens.*` — a machine has no business listing or ending the
     *    humans' device sessions.
     *  - `settings.api-keys.*` — a key that can mint keys is a key that cannot
     *    be revoked, and one that can revoke them can lock the tenant out.
     *  - `settings.profile.*`, `settings.password.update` — account takeover.
     *  - `settings.team.*` — role escalation, and the roster is personal data.
     *  - `billing.*` — money. Reading the plan is not worth widening this.
     *  - `integrations.store|update|destroy` — these carry platform
     *    credentials (invariant I5) and delete ingested data.
     *  - `notifications.*`, `settings.notifications.*`,
     *    `auth.email.resend` — addressed to a person, not to a program.
     *
     * @var list<string>
     */
    public const MACHINE_ROUTES = [
        'api.v1.auth.logout',
        'api.v1.auth.me',
        'api.v1.feedbacks.index',
        'api.v1.feedbacks.show',
        'api.v1.integrations.index',
        'api.v1.integrations.platforms',
        'api.v1.integrations.show',
        'api.v1.integrations.sync',
        'api.v1.overview.kpis',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! TokenAbility::isApiKey($token)) {
            return $next($request);
        }

        if (! self::allowsApiKeys($request->route()?->getName())) {
            // FORBIDDEN, not NOT_FOUND: the route exists and the caller is
            // authenticated for the right tenant. Nothing about the tenant's
            // data is disclosed by saying so, which is what invariant I1's
            // 404 rule is protecting.
            throw new ApiException(ApiErrorCode::Forbidden);
        }

        return $next($request);
    }

    public static function allowsApiKeys(?string $routeName): bool
    {
        return $routeName !== null && in_array($routeName, self::MACHINE_ROUTES, true);
    }
}
