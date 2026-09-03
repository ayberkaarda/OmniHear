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
                // Global, not scoped to the company — and that is a reversal of
                // what this rule used to say.
                //
                // It was scoped so a validation message could not reveal that
                // an address has an account in another tenant. But `users.email`
                // carries a **global** UNIQUE index
                // (0001_01_01_000000_create_users_table.php), so a scoped check
                // happily issued an invitation that could never be accepted:
                // the insert at accept time would hit the index. That left two
                // outcomes, and both are worse than the leak this rule was
                // avoiding — a 500 on the invitee's very first request, or the
                // "obvious fix" of attaching the existing user to the inviting
                // company, which is the definition of cross-tenant account
                // takeover.
                //
                // The enumeration concern is answered by the message instead of
                // by the scope: messages() below maps this failure to exactly
                // the same sentence for an in-company collision and for a
                // collision in a tenant the caller cannot see, so the two are
                // indistinguishable from outside. It is the same sentence the
                // accept endpoint returns, for the same reason.
                Rule::unique('users', 'email'),
            ],
            'role' => ['required', 'string', Rule::in(User::ROLES)],
        ];
    }

    /**
     * One sentence for every uniqueness outcome.
     *
     * Laravel's default `unique` message ("The email has already been taken.")
     * would already be identical in both cases; this states the requirement
     * rather than relying on that, and says something the inviter can act on.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => (string) __('invitations.email_taken'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}
