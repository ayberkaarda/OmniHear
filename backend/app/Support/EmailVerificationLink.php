<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The verification link is signed for the API route but delivered to the SPA.
 *
 * Signatures are *relative* on purpose: the mail is rendered by a queue worker
 * (where the URL root comes from APP_URL) while verification arrives over HTTP
 * (where it comes from the request). A relative signature covers only the path
 * and query, so the two sides cannot disagree about the host.
 */
class EmailVerificationLink
{
    public const ROUTE = 'api.v1.auth.email.verify';

    /**
     * Absolute SPA URL to put in the e-mail.
     */
    public static function forUser(User $user, CarbonInterface $expiresAt): string
    {
        $signed = URL::temporarySignedRoute(
            self::ROUTE,
            $expiresAt,
            ['id' => $user->getKey(), 'hash' => sha1((string) $user->getEmailForVerification())],
            absolute: false,
        );

        $query = (string) parse_url($signed, PHP_URL_QUERY);

        return rtrim((string) config('app.frontend_url'), '/').'/auth/verify-email?'.$query;
    }

    /**
     * Re-derives the signed URL from the four values the SPA forwarded and
     * checks both the signature and the expiry.
     *
     * Laravel ksorts signed-route parameters before hashing them, so the
     * rebuilt query string has to be sorted the same way or the HMAC will not
     * match even for a genuine link.
     */
    public static function isValid(int $id, string $hash, int $expires, string $signature): bool
    {
        $parameters = ['id' => $id, 'hash' => $hash, 'expires' => $expires];
        ksort($parameters);

        $url = URL::route(self::ROUTE, $parameters, absolute: false)
            .'&signature='.rawurlencode($signature);

        return URL::hasValidSignature(Request::create($url), absolute: false);
    }
}
