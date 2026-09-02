<?php

namespace App\Http\Requests\Api\V1\Integration;

use App\Models\Integration;
use App\Support\Connectors\ConnectorFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateIntegrationRequest extends FormRequest
{
    private ?Integration $resolved = null;

    public function authorize(): bool
    {
        Gate::authorize('update', $this->integration());

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $connectors = app(ConnectorFactory::class);
        $platform = (string) $this->integration()->platform;

        $rules = [
            // Immutable. The stored sync_cursor is encoded by the connector that
            // wrote it; repointing the row at another platform would hand a
            // foreign cursor to a connector that cannot read it, and would
            // orphan every feedback row already ingested under the old one.
            'platform' => ['prohibited'],
            'settings' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'array'],
            // 'error' is a system verdict, not a user input: it is written by a
            // failed run and cleared by a successful one.
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'settings.fixture_set' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];

        $config = $connectors->config($platform) ?? [];

        foreach ($config['required_settings'] ?? [] as $key) {
            // A PATCH replaces the settings object wholesale, so a partial one
            // would silently drop the keys the connector cannot run without.
            $rules['settings.'.$key] = array_merge(
                ['required_with:settings', 'string', 'max:255'],
                IntegrationSettingFormats::for($key),
            );
        }

        // Same reasoning for a credential rotation: sending `credentials` at
        // all replaces the stored object, so every key the connector needs has
        // to be present in the new one.
        foreach ($config['required_credentials'] ?? [] as $key) {
            $rules['credentials.'.$key] = ['required_with:credentials', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * Resolved here rather than by implicit route binding: SubstituteBindings
     * runs before SetTenantContext (it is in Laravel's $middlewarePriority list
     * and our middleware is not), so a bound model would be queried with no
     * tenant in context. A form request is resolved after the whole middleware
     * stack, so this is safe — and findOrFail keeps another tenant's id a 404.
     */
    public function integration(): Integration
    {
        return $this->resolved ??= Integration::query()->findOrFail($this->route('integration'));
    }
}
