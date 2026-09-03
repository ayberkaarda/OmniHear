<?php

namespace App\Http\Requests\Api\V1\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * PATCH /api/v1/settings/password.
 *
 * A wrong `current_password` is a 422 on that field rather than a 401: the
 * caller is authenticated, it is the confirmation that failed. Laravel's
 * `current_password` rule compares against the guard's own user, so nothing
 * here reads or moves the stored hash.
 */
class UpdatePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
                Password::defaults(),
            ],
        ];
    }
}
