<?php

namespace App\Http\Requests\Api\V1\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POST /api/v1/settings/api-keys. Owner or admin.
 */
class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', PersonalAccessToken::class);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
