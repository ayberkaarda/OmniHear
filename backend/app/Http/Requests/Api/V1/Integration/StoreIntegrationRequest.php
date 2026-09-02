<?php

namespace App\Http\Requests\Api\V1\Integration;

use App\Models\Integration;
use App\Support\Connectors\ConnectorFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreIntegrationRequest extends FormRequest
{
    /**
     * Gate::authorize() rather than a boolean return: it throws an
     * AuthorizationException carrying the policy's own status, so a
     * denyAsNotFound() surfaces as 404 instead of being flattened to 403.
     */
    public function authorize(): bool
    {
        Gate::authorize('create', Integration::class);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $connectors = app(ConnectorFactory::class);
        $platform = $this->input('platform');

        $rules = [
            // Only platforms with a connector. The schema allows more values
            // because later phases add them; accepting one now would create an
            // integration the scheduler can never sync.
            'platform' => ['required', 'string', Rule::in($connectors->platforms())],
            'settings' => ['sometimes', 'nullable', 'array'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'settings.fixture_set' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];

        $config = is_string($platform) ? ($connectors->config($platform) ?? []) : [];
        $required = $config['required_settings'] ?? [];

        if ($required !== []) {
            $rules['settings'] = ['required', 'array'];
        }

        foreach ($required as $key) {
            $rules['settings.'.$key] = array_merge(
                ['required', 'string', 'max:255'],
                IntegrationSettingFormats::for($key),
            );
        }

        // A platform that needs credentials must be given them at create time.
        // The connector would otherwise raise Misconfigured on the first
        // scheduled run, which surfaces as a broken integration hours later
        // instead of a 422 the user can act on.
        $requiredCredentials = $config['required_credentials'] ?? [];

        if ($requiredCredentials !== []) {
            $rules['credentials'] = ['required', 'array'];
        }

        foreach ($requiredCredentials as $key) {
            $rules['credentials.'.$key] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }
}
