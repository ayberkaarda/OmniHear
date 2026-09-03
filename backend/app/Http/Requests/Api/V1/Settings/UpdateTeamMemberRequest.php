<?php

namespace App\Http\Requests\Api\V1\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/settings/team/{user}.
 *
 * `changeRole` rather than `update`: UserPolicy::update is the "may edit this
 * user" question and deliberately lets a member edit itself, which is not the
 * same permission as moving somebody between roles.
 */
class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('changeRole', $this->route('user'));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(User::ROLES)],
        ];
    }
}
