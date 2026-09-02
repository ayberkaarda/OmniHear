<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password
     *
     * Always 202, whether or not the address exists: the response must not tell
     * a caller which e-mails are registered.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => (string) $request->string('email')]);

        return response()->json(
            ['message' => (string) __('messages.password_reset_link_sent')],
            Response::HTTP_ACCEPTED
        );
    }

    /**
     * POST /api/v1/auth/reset-password
     *
     * On success every existing token for the user is revoked, so a stolen
     * session cannot outlive the reset.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $plain): void {
                $user->forceFill([
                    'password' => $plain,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [(string) __($status)],
            ]);
        }

        return response()->json(['message' => (string) __('messages.password_reset')]);
    }
}
