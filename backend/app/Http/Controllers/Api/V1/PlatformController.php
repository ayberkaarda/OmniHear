<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Integration\IntegrationSettingFormats;
use App\Support\Connectors\ConnectorFactory;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/integrations/platforms
 * (docs/contracts/settings-api.md section 5).
 *
 * The connectable platforms, as the registry actually holds them.
 *
 * This exists because the frontend was hand-copying config/connectors.php into
 * a constant. Zendesk was added on the backend while that copy was being
 * written and the mismatch was caught by hand; the next one would have reached
 * a user as a 422 from a form offering a platform the API refuses, or as a
 * platform the API accepts and the form never showed. Serving the registry
 * makes the integration form server-driven and removes the copy that can drift.
 *
 * `credentials` carries key names and whether they are required. Never a value:
 * a credential that has been written is never read back over the wire in any
 * shape (invariant I5).
 */
class PlatformController extends Controller
{
    public function index(ConnectorFactory $connectors): JsonResponse
    {
        $data = [];

        foreach ($connectors->platforms() as $platform) {
            $config = $connectors->config($platform) ?? [];

            $credentials = array_map(
                fn (string $key): array => ['key' => $key, 'required' => true],
                $config['required_credentials'] ?? [],
            );

            $data[] = [
                'platform' => $platform,
                'requires_credentials' => $credentials !== [],
                'settings' => array_merge(
                    $this->settings($config['required_settings'] ?? [], required: true),
                    $this->settings($config['optional_settings'] ?? [], required: false),
                ),
                'credentials' => $credentials,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * `format` is the name of the extra shape rule the server enforces, or null
     * when the setting is validated as a plain string. It is read from the same
     * class the form requests read, so this endpoint cannot advertise a
     * constraint that is not actually applied.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, required: bool, format: string|null}>
     */
    private function settings(array $keys, bool $required): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'required' => $required,
            'format' => IntegrationSettingFormats::name($key),
        ], $keys);
    }
}
