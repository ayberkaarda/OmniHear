<?php

namespace App\Http\Requests\Api\V1\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/settings/team/invitations.
 *
 * `authorize()` answers only "may this caller invite at all" (owner or admin).
 * The seniority rule — nobody invites above their own role, and only an owner
 * grants owner — is checked in the controller, after validation has established
 * that `role` is one of the three known values. Doing it here would turn a
 * typo'd role into a 403 instead of the 422 it is.
 */
class InviteTeamMemberRequest extends FormRequest
{
    /**
     * Gate::authorize() rather than a boolean: it throws with the policy's own
     * status, so a denyAsNotFound() would surface as 404 rather than being
     * flattened to 403.
     */
    public function authorize(): bool
    {
        Gate::authorize('create', User::class);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                // Scoped to the company on purpose. A global uniqueness check
                // would answer "this address is taken" for an account in
                // another tenant, which is cross-tenant account enumeration
                // through a validation message (invariant I1).
                Rule::unique('users', 'email')
                    ->where('company_id', $this->user()?->getAttribute('company_id')),
            ],
            'role' => ['required', 'string', Rule::in(User::ROLES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}
