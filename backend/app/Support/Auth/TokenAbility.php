<?php

namespace App\Support\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * The line between a device session and an API key.
 *
 * Both are Sanctum personal access tokens in the same table. Without something
 * telling them apart, `GET /auth/tokens` and `GET /settings/api-keys` list the
 * same rows and either screen's "revoke" button kills the other's credential —
 * a user ending a laptop session would silently break a server-to-server
 * integration. docs/contracts/settings-api.md section 3.
 *
 * The separator is the ability list:
 *
 *   device session  POST /auth/login              ['session']
 *   API key         POST /settings/api-keys       ['api']
 *
 * # Why `api` is matched literally and `session` is matched by elimination
 *
 * Tokens minted before this distinction carry Sanctum's default `['*']`, and
 * PersonalAccessToken::can('api') answers *true* for a wildcard. Classifying by
 * `can()` would therefore promote every legacy session to an API key — the
 * exact confusion this class exists to prevent. So a token is an API key only
 * when it carries the literal `api` ability, and everything else is a session.
 * That is also what the contract asks for: "existing tokens created before this
 * distinction carry ['*']; treat them as sessions."
 */
final class TokenAbility
{
    public const SESSION = 'session';

    public const API = 'api';

    /**
     * @return list<string>
     */
    public static function session(): array
    {
        return [self::SESSION];
    }

    /**
     * @return list<string>
     */
    public static function api(): array
    {
        return [self::API];
    }

    public static function isApiKey(PersonalAccessToken $token): bool
    {
        $abilities = $token->abilities;

        return is_array($abilities) && in_array(self::API, $abilities, true);
    }

    public static function isSession(PersonalAccessToken $token): bool
    {
        return ! self::isApiKey($token);
    }
}
