<?php

namespace App\Http\Requests\Api\V1\Integration;

/**
 * Extra shape rules for individual connector settings.
 *
 * `required_settings` in config/connectors.php only says a key must be present.
 * Some of those keys end up in a URL, and for those "a non-empty string under
 * 255 characters" is not enough: the connector would accept the value, build a
 * request around it and only then discover the problem — at sync time, hours
 * after the create that caused it.
 *
 * This is the create/update mirror of the whitelists the connectors enforce for
 * themselves. Both layers stay: this one gives the user a 422 they can act on,
 * and ConnectorFactory refuses the value again for anything that reached the
 * database another way.
 */
final class IntegrationSettingFormats
{
    /**
     * The public name of the extra rule, or null when a setting is validated as
     * a plain string.
     *
     * Published by `GET /api/v1/integrations/platforms` so the integration form
     * can apply the same constraint client-side. It is derived from the same
     * match arms as `for()` below, so a format cannot be advertised that the
     * server does not actually enforce — which is the whole point of that
     * endpoint existing.
     */
    public static function name(string $key): ?string
    {
        return match ($key) {
            'subdomain' => 'hostname',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function for(string $key): array
    {
        return match ($key) {
            // Substituted into the Zendesk host. A value carrying `/`, `@`, `:`
            // or a dot would send every authenticated request, Authorization
            // header included, somewhere else entirely. DNS label rules.
            'subdomain' => ['max:63', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/'],
            default => [],
        };
    }
}
