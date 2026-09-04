<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/v1/auth/two-factor.
 *
 * Both factors, again. Turning the second factor off is the single most
 * valuable thing an attacker sitting on a stolen session token can do — it is
 * the step that converts temporary access into a password-only account they can
 * keep — so it is the one place where holding a valid session is deliberately
 * not enough.
 *
 * `current_password` compares against the guard's own user, so nothing here
 * reads or moves the stored hash (the same arrangement as
 * Settings\UpdatePasswordRequest).
 */
class DisableTwoFactorRequest extends FormRequest
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
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function code(): string
    {
        return trim((string) $this->input('code'));
    }
}
