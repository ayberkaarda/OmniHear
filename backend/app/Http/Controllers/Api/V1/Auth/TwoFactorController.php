<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Api\V1\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Api\V1\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\RecoveryCodes;
use App\Support\Auth\TokenAbility;
use App\Support\Auth\TokenLifetime;
use App\Support\Auth\Totp;
use App\Support\Auth\TotpQrCode;
use App\Support\Auth\TwoFactorChallenge;
use App\Support\Auth\TwoFactorReplayGuard;
use App\Support\Http\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * TOTP two-factor authentication (docs/contracts/w10-two-factor.md).
 *
 * `UserResource` has published `two_factor_enabled` since F2 and
 * `users.two_factor_secret` has existed since the tenancy migration, but no
 * code path could ever write either: the field was structurally always `false`.
 * This controller is what makes the promise true.
 *
 * # The four session endpoints and the one public one
 *
 * Enrolment, confirmation, disabling and recovery-code regeneration all belong
 * to somebody who is already signed in, and sit on the authenticated surface.
 * The challenge does not: its whole purpose is to serve a caller who is *half*
 * signed in, holding a challenge token that `EnforceTokenAbility` refuses
 * everywhere else. So it is a public route that resolves the bearer token
 * itself, and the abilities on that token are the entire authorization.
 *
 * # Secrets leave here exactly three times (invariant I5)
 *
 * The secret in the enrolment response, the recovery codes in the confirmation
 * response, and the recovery codes again on regeneration. Nowhere else: not in
 * `UserResource`, not in an audit row, not in a log line. `$hidden` on the
 * model and `sensitive-log-guard` cover the rest of the surface, but the reason
 * those three are safe is worth stating - each returns a value the server has
 * *just minted*, to the one caller entitled to it, before it is stored in a
 * form nothing can read back. None of them reads a stored secret out again.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly TwoFactorChallenge $challenges,
        private readonly TwoFactorReplayGuard $replay,
    ) {}

    /**
     * POST /api/v1/auth/two-factor - begin enrolment.
     *
     * The secret is returned in this response and never in another one. Calling
     * this again before confirming replaces the pending secret, which is what
     * lets a user who lost the tab, or scanned into the wrong app, start over
     * without support; calling it after confirming is a conflict, because
     * silently replacing a working second factor is how an attacker on a stolen
     * session would migrate it to a device they hold.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->twoFactorEnabled()) {
            throw new ApiException(ApiErrorCode::TwoFactorAlreadyEnabled);
        }

        $secret = Totp::generateSecret();

        // Any half-finished previous attempt goes with it: the recovery codes
        // of an unconfirmed enrolment belong to a secret that no longer exists.
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $uri = Totp::provisioningUri($secret, $user->email, (string) config('app.name'));

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $uri,
            'qr_svg_data_uri' => TotpQrCode::dataUri($uri),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/auth/two-factor/confirm - finish enrolment.
     *
     * The code proves the user can actually read one from the secret they were
     * given. Only after that does `two_factor_confirmed_at` - and therefore
     * `twoFactorEnabled()`, and therefore the login gate - turn on.
     */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->twoFactorEnabled()) {
            throw new ApiException(ApiErrorCode::TwoFactorAlreadyEnabled);
        }

        if (! $user->twoFactorPending()) {
            throw new ApiException(ApiErrorCode::TwoFactorNotEnabled);
        }

        $this->verifyCode($user, $request->code());

        $codes = $this->recoveryCodes->generate();
        $this->recoveryCodes->store($user, $codes);

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->audit->record(AuditAction::TwoFactorEnabled, actor: $user);

        return response()->json(['recovery_codes' => $codes]);
    }

    /**
     * DELETE /api/v1/auth/two-factor - turn it off.
     *
     * Password *and* code: see DisableTwoFactorRequest for why holding the
     * session is deliberately not enough here.
     */
    public function destroy(DisableTwoFactorRequest $request): Response
    {
        $user = $this->user($request);

        if (! $user->twoFactorEnabled()) {
            throw new ApiException(ApiErrorCode::TwoFactorNotEnabled);
        }

        $this->verifyCode($user, $request->code());

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $this->audit->record(AuditAction::TwoFactorDisabled, actor: $user);

        return response()->noContent();
    }

    /**
     * POST /api/v1/auth/two-factor/recovery-codes - mint a fresh set.
     *
     * Requires a current code, because the reason to regenerate is usually that
     * the old list has been seen by someone else - and an endpoint that hands
     * out a working second factor to anyone holding a session would be a
     * quieter version of the takeover DELETE is protected against.
     */
    public function recoveryCodes(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user->twoFactorEnabled()) {
            throw new ApiException(ApiErrorCode::TwoFactorNotEnabled);
        }

        $this->verifyCode($user, $request->code());

        $codes = $this->recoveryCodes->generate();
        $this->recoveryCodes->store($user, $codes);

        return response()->json(['recovery_codes' => $codes]);
    }

    /**
     * POST /api/v1/auth/two-factor/challenge - the second step of a login.
     *
     * Public by necessity: the caller holds a challenge token, which
     * `EnforceTokenAbility` refuses on every route behind `auth:sanctum`. The
     * success body is the same shape `/auth/login` returns for a completed
     * login, so the SPA has one success path and not two.
     */
    public function challenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $token = $this->challengeToken($request);
        $user = $token->tokenable;

        if (! $user instanceof User || ! $user->twoFactorEnabled()) {
            // The token outlived the factor it was issued for: 2FA was turned
            // off, or the user was erased, between the password and the code.
            $this->destroyChallengeToken($token);

            throw new ApiException(ApiErrorCode::Unauthenticated);
        }

        if (! $this->challengeSatisfied($request, $user)) {
            $this->audit->record(AuditAction::TwoFactorChallengeFailed, actor: $user);

            if ($this->challenges->recordFailure($token)) {
                $this->destroyChallengeToken($token);
            }

            throw new ApiException(ApiErrorCode::TwoFactorCodeInvalid);
        }

        $this->destroyChallengeToken($token);

        $user->forceFill(['last_login_ip' => $request->ip()])->save();

        $this->audit->record(AuditAction::LoginSucceeded, actor: $user);

        return response()->json([
            'token' => $user->createToken('web', TokenAbility::session(), TokenLifetime::session())->plainTextToken,
            'user' => new UserResource($user),
            'company' => new CompanyResource($user->company),
        ]);
    }

    /**
     * Either factor: the authenticator code or one recovery code.
     *
     * A recovery code is spent whether or not the rest of the request
     * succeeds - it has been transmitted, so it is burned.
     */
    private function challengeSatisfied(TwoFactorChallengeRequest $request, User $user): bool
    {
        $recovery = $request->recoveryCode();

        if ($recovery !== null) {
            return $this->recoveryCodes->consume($user, $recovery);
        }

        $code = $request->totpCode();

        return $code !== null && $this->acceptCode($user, $code);
    }

    /**
     * The challenge credential, or 401.
     */
    private function challengeToken(TwoFactorChallengeRequest $request): PersonalAccessToken
    {
        $token = $this->challenges->resolve($request->bearerToken());

        if ($token === null) {
            throw new ApiException(ApiErrorCode::Unauthenticated);
        }

        return $token;
    }

    private function destroyChallengeToken(PersonalAccessToken $token): void
    {
        $this->challenges->forget($token);
        $token->delete();
    }

    /**
     * Verify a TOTP code for a session endpoint, or raise the catalogued 422.
     */
    private function verifyCode(User $user, string $code): void
    {
        if (! $this->acceptCode($user, $code)) {
            throw new ApiException(ApiErrorCode::TwoFactorCodeInvalid);
        }
    }

    /**
     * A code is accepted once, and only once: the step it belongs to is spent
     * on success, and a later attempt at or below that step is refused even
     * though the arithmetic still matches. See TwoFactorReplayGuard.
     */
    private function acceptCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $step = Totp::verify($secret, $code, $this->replay->lastAcceptedStep($user));

        if ($step === null) {
            return false;
        }

        $this->replay->markAccepted($user, $step);

        return true;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiException(ApiErrorCode::Unauthenticated);
        }

        return $user;
    }
}
