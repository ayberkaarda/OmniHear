<?php

namespace App\Support\Connectors;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mints the OAuth access token GooglePlayConnector puts in its Authorization
 * header, from a Google service account.
 *
 * It is a class of its own rather than three private methods on the connector
 * because the two concerns fail differently and are tested differently: the
 * connector fetches pages, and this one performs a credential exchange with its
 * own error vocabulary, its own cache and its own clock.
 *
 * ## The exchange
 *
 *  1. Build a JWT asserting `iss = client_email`,
 *     `scope = https://www.googleapis.com/auth/androidpublisher`,
 *     `aud = https://oauth2.googleapis.com/token`, `iat` and `exp`, and sign it
 *     RS256 with the service account private key.
 *  2. POST it to the token endpoint with
 *     `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer` and read back an
 *     `access_token` and its `expires_in`.
 *
 * `openssl_sign` with `OPENSSL_ALGO_SHA256` does step 1; there is no JWT
 * dependency, and none is needed for a two-segment signing input.
 *
 * ## Invariant I5 — this holds the worst credential in the codebase
 *
 * A service-account private key is a permanent, non-rotating bearer of an
 * entire Play Console account, so the rules ZendeskConnector states are applied
 * here without exception and with one addition:
 *
 *  1. `$clientEmail` and `$privateKey` are private constructor properties. They
 *     reach exactly two places — the signer, and the `iss` claim of the JWT
 *     this object builds. Never a URL, never a query string, never read back.
 *  2. Nothing thrown here is built from a response. Every failure is one of the
 *     fixed ConnectorFailure sentences, so an upstream body echoing the
 *     assertion back could still not reach `integrations.sync_error`.
 *  3. This class logs nothing at all.
 *  4. **The private key is never serialized and never reaches an exception.**
 *     A key OpenSSL refuses produces Misconfigured and nothing at all derived
 *     from `openssl_error_string()` — that buffer holds fragments of the
 *     material it failed to parse.
 *
 * ## The cache key
 *
 * Derived from the integration id and nothing else. Deriving it from the client
 * email, or from a hash of the key, would put credential material into a cache
 * key — a string that travels through Redis and every cache-inspection tool —
 * and two tenants that happened to configure the same service account would
 * then share a token, which is the tenant-isolation half of the same mistake
 * (invariant I1).
 */
final class GooglePlayAccessToken
{
    /**
     * The scope reviews.list is documented to require.
     */
    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    /**
     * Lifetime asserted in the JWT. Google documents one hour as the maximum
     * and refuses anything longer.
     */
    private const ASSERTION_LIFETIME = 3600;

    /**
     * How far ahead of the real expiry the cached token is dropped.
     *
     * A run that starts with thirty seconds left on the token would still be
     * holding it when a later page is requested, and the retry for that page is
     * a whole queue round trip. 120 seconds is longer than any single run of
     * this connector and far shorter than any usable token lifetime.
     */
    private const EXPIRY_MARGIN = 120;

    public function __construct(
        private string $clientEmail,
        private string $privateKey,
        private int $integrationId,
        private string $tokenUrl,
        private int $timeout,
    ) {}

    /**
     * @throws ConnectorException
     */
    public function get(): string
    {
        $key = $this->cacheKey();
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $expiresIn] = $this->mint();

        // No expiry means no safe TTL, and caching it "for a while" would be a
        // guess that can outlive the token. Not caching costs one extra
        // exchange per page and is always correct.
        if ($expiresIn !== null && $expiresIn > self::EXPIRY_MARGIN) {
            Cache::put($key, $token, $expiresIn - self::EXPIRY_MARGIN);
        }

        return $token;
    }

    /**
     * Derived from the integration id alone, so two tenants can never share a
     * token even when they configure the same service account.
     */
    private function cacheKey(): string
    {
        return 'connector:googleplay:access-token:'.$this->integrationId;
    }

    /**
     * @return array{0: string, 1: int|null}
     *
     * @throws ConnectorException
     */
    private function mint(): array
    {
        $assertion = $this->assertion();

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($this->timeout)
                ->post($this->tokenUrl, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (ConnectionException $e) {
            throw ConnectorException::of(ConnectorFailure::Unreachable, $e);
        }

        if (! $response->successful()) {
            throw ConnectorException::of(match (true) {
                // `invalid_grant` (a key that no longer matches the account, a
                // clock too far out, a revoked account) and `unauthorized_client`
                // both arrive as 400 here, and both are terminal: retrying an
                // identical assertion produces an identical refusal.
                in_array($response->status(), [400, 401, 403], true) => ConnectorFailure::InvalidCredentials,
                $response->status() === 429 => ConnectorFailure::RateLimited,
                default => ConnectorFailure::Unreachable,
            });
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        $minted = $body['access_token'] ?? null;

        if (! is_string($minted) || $minted === '') {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        $expiresIn = $body['expires_in'] ?? null;

        return [$minted, is_numeric($expiresIn) ? (int) $expiresIn : null];
    }

    /**
     * The signed JWT bearer assertion.
     *
     * @throws ConnectorException
     */
    private function assertion(): string
    {
        $issuedAt = CarbonImmutable::now()->getTimestamp();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $this->tokenUrl,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::ASSERTION_LIFETIME,
        ];

        $input = $this->segment($header).'.'.$this->segment($claims);

        return $input.'.'.$this->base64Url($this->sign($input));
    }

    /**
     * RS256 over the two encoded segments.
     *
     * Every failure path collapses to the same fixed sentence on purpose. A key
     * OpenSSL cannot parse, a key of the wrong type and a signing failure are
     * indistinguishable to the user and all mean the same thing — the stored
     * credential is not a usable service-account key. The only extra detail
     * available is `openssl_error_string()`, a buffer that can carry fragments
     * of the key material it failed to parse, so it is never read.
     *
     * @throws ConnectorException
     */
    private function sign(string $input): string
    {
        try {
            // Suppressed and then caught, which looks like belt and braces and
            // is not: a malformed PEM raises a warning whose text is OpenSSL
            // rendering of what it just failed to parse, and where that lands
            // depends on which error handler is installed — the framework one
            // turns it into an ErrorException, a bare PHP one prints it. `@`
            // closes the printing path and the catch closes the throwing one.
            $key = @openssl_pkey_get_private($this->privateKey);
        } catch (Throwable) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        if ($key === false) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        $signature = '';

        try {
            $signed = @openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);
        } catch (Throwable) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        if ($signed !== true || $signature === '') {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        return $signature;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function segment(array $payload): string
    {
        return $this->base64Url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
