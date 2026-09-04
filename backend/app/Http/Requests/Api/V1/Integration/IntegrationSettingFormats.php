<?php

namespace App\Http\Requests\Api\V1\Integration;

/**
 * Extra shape rules for individual connector settings and credentials.
 *
 * `required_settings` and `required_credentials` in config/connectors.php only
 * say a key must be present. Some of those keys end up in a URL, and for those
 * "a non-empty string under 255 characters" is not enough: the connector would
 * accept the value, build a request around it and only then discover the
 * problem — at sync time, hours after the create that caused it. `email`'s
 * `session_url` is the first credential in this position; the earlier entries
 * here are all settings.
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
            'package_name' => 'android_package',
            'business_unit_id' => 'hex24',
            'session_url' => 'https_url',
            'instance_url' => 'https_url',
            'hashtag' => 'hashtag',
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
            // Substituted into the Google Play API path. Java package syntax,
            // which excludes `/` and `.` sequences that could climb out of the
            // applications/{package}/reviews segment. Mirrors
            // GooglePlayConnector::PACKAGE_NAME_PATTERN; both layers stay,
            // because a row that reached the database another way still has to
            // be refused at sync time.
            'package_name' => ['max:255', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/'],
            // Substituted into the Trustpilot API path. Mirrors
            // TrustpilotConnector::BUSINESS_UNIT_ID.
            'business_unit_id' => ['regex:/^[a-f0-9]{24}$/i'],
            // A credential rather than a setting — the shape rule applies here
            // for the same reason: every later JMAP request, bearer token
            // included, goes wherever this URL points. Mirrors
            // EmailConnector::isHttpsUrl(); a non-https value would otherwise be
            // accepted here and only fail once the connector runs, hours later.
            'session_url' => ['max:2048', 'url', 'regex:/^https:\/\//i'],
            // The mailbox is matched by name against the fetched list
            // (EmailConnector::mailboxId()), not substituted into a request, so
            // it needs no URL/path whitelist. The one gap worth closing here is
            // whitespace-only: `required` accepts a string of blanks, and the
            // connector's own trim() check would only catch it once the
            // scheduler runs.
            'mailbox' => ['regex:/\S/'],
            // The instance URL is substituted into every request this
            // connector makes, so the same reasoning as `session_url` applies
            // verbatim: a non-https value would otherwise be accepted here
            // and only fail once MastodonConnector::isHttpsUrl() runs, hours
            // later. Same rule, reused rather than duplicated.
            'instance_url' => ['max:2048', 'url', 'regex:/^https:\/\//i'],
            // Substituted into the Mastodon timeline URL path, so it is
            // whitelisted rather than escaped — the same discipline as
            // Trustpilot's business_unit_id and Google Play's package_name.
            // Mirrors MastodonConnector::HASHTAG; both layers stay, because a
            // row that reached the database another way still has to be
            // refused at sync time.
            'hashtag' => ['max:100', 'regex:/^[\p{L}\p{N}_]{1,100}$/u'],
            default => [],
        };
    }
}
