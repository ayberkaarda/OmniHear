<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/two-factor — begin enrolment.
 *
 * The password, again, and for the mirror image of the reason
 * DisableTwoFactorRequest asks for it. Turning a second factor off converts a
 * stolen session into a password-only account the attacker can keep; beginning
 * enrolment on an account that has none is the same move made forwards — the
 * attacker arms *their own* authenticator, walks off with the eight recovery
 * codes shown once, and locks the real owner out of their own account even
 * though the owner still knows the password. Both are the point at which the
 * durability of a session is decided, so both re-prove the password rather than
 * trusting the session alone.
 *
 * `current_password` compares against the guard's own user, so nothing here
 * reads or moves the stored hash (the same arrangement as
 * Settings\UpdatePasswordRequest and DisableTwoFactorRequest).
 */
class EnableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
        ];
    }
}
