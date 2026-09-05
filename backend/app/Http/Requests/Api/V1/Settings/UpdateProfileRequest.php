<?php

namespace App\Http\Requests\Api\V1\Settings;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/settings/profile. Both fields are optional; a body with
 * neither is a legal no-op.
 *
 * # Why an email change re-proves the password
 *
 * Moving the address is a full account takeover in one step: the new mailbox
 * receives the password-reset link, so whoever controls it owns the account.
 * ProfileController already un-verifies the account on a change, which stops the
 * attacker inheriting the *proven* status of the old mailbox — but nothing there
 * stops a stolen session pointing the reset flow at an inbox the attacker does
 * control. The password gate is that other half. A change of name is not that,
 * so it must not be taxed with a password prompt.
 *
 * The rule is conditional, not static, which is exactly what `$validator
 * ->sometimes()` expresses: `password` is required *and* must equal the current
 * one only when the submitted address differs from the one on file. Declaring
 * it in `rules()` instead would either force the password on every name-only
 * PATCH (a static `required`) or lie about when it fires (a `required_with`
 * that triggers on an unchanged email that happens to be present in the body).
 * Keeping it out of `rules()` also keeps the generated OpenAPI document honest:
 * that document reads `rules()` and cannot express a cross-field condition, so
 * a half-rule left there to decorate it would misstate the endpoint.
 */
class UpdateProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:255',
                // Ignoring the caller's own row keeps a PATCH that re-sends the
                // current address from failing as a duplicate of itself.
                Rule::unique('users', 'email')->ignore($this->user()?->getKey()),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Only when the address actually moves. `sometimes()` runs its closure
        // after the primary rules, against the (already normalised) input, and
        // adds the rule set only when the closure is true — so a name-only
        // change never sees `required`, and a re-sent unchanged address never
        // sees `current_password`.
        $validator->sometimes(
            'password',
            ['required', 'string', 'current_password'],
            fn (): bool => $this->emailIsChanging(),
        );
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * True when the body carries an email that differs from the user's current
     * one. The comparison is against the normalised value written by
     * prepareForValidation, so a re-send of the same address in a different case
     * is not a change — the same test ProfileController::update() applies.
     */
    private function emailIsChanging(): bool
    {
        $email = $this->input('email');

        return is_string($email) && $email !== $this->user()?->email;
    }
}
