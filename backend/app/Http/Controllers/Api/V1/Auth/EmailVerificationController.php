<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\VerifyEmailRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\EmailVerificationLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    /**
     * POST /api/v1/auth/email/verify
     *
     * The emailed link points at the SPA; the SPA forwards the four signed
     * values here. Idempotent: an already verified user still gets 200.
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $id = $request->integer('id');
        $hash = (string) $request->string('hash');

        $valid = EmailVerificationLink::isValid(
            $id,
            $hash,
            $request->integer('expires'),
            (string) $request->string('signature'),
        );

        $user = $valid ? User::query()->find($id) : null;

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw ValidationException::withMessages([
                'signature' => [(string) __('messages.verification_link_invalid')],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json(['user' => new UserResource($user->fresh())]);
    }

    /**
     * POST /api/v1/auth/email/resend
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(
            ['message' => (string) __('messages.verification_link_sent')],
            Response::HTTP_ACCEPTED
        );
    }
}
