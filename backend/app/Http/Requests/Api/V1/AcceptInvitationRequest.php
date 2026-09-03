<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/v1/invitations/{token}/accept.
 *
 * Public by design: the recipient has no account yet, so there is nothing to
 * authorize against. The token in the path is the credential, and the
 * controller is what validates it — a request that reaches these rules has not
 * yet been told whether the token is any good, and must not be able to tell
 * the difference from the shape of the failure.
 *
 * No `email` member. The address is fixed by the invitation row: accepting an
 * invitation with a different address would let anyone holding a forwarded
 * mail put an arbitrary account inside somebody else's tenant.
 */
class AcceptInvitationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Password::defaults() rather than a literal rule set, so the one
            // place that defines password strength (AppServiceProvider) governs
            // this door exactly as it governs registration and the reset flow.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
