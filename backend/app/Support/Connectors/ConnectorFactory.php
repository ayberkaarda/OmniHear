<?php

namespace App\Support\Connectors;

use App\Models\Integration;

/**
 * Builds the connector for an integration from config/connectors.php.
 *
 * Constructing connectors here rather than inside the job keeps FetchFeedbackJob
 * free of any per-platform knowledge: adding a platform is a config entry plus a
 * case below, and the ingestion path does not change.
 */
class ConnectorFactory
{
    /**
     * @throws ConnectorException when the platform has no connector or the
     *                            integration is missing a required setting
     */
    public function for(Integration $integration): PlatformConnector
    {
        $platform = (string) $integration->platform;
        $config = $this->config($platform);

        if ($config === null) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        $settings = is_array($integration->settings) ? $integration->settings : [];

        foreach ($config['required_settings'] ?? [] as $key) {
            if (! isset($settings[$key]) || $settings[$key] === '') {
                throw ConnectorException::of(ConnectorFailure::Misconfigured);
            }
        }

        $limits = new ConnectorLimits(
            maxPagesPerRun: (int) ($config['max_pages_per_run'] ?? 10),
            maxConsecutiveEmptyPages: (int) ($config['max_consecutive_empty_pages'] ?? 3),
        );

        return match ($config['connector'] ?? null) {
            FixtureConnector::class => new FixtureConnector(
                directory: $this->fixtureDirectory($settings),
                limits: $limits,
            ),
            AppStoreConnector::class => new AppStoreConnector(
                appId: (string) $settings['app_id'],
                country: (string) $settings['country'],
                baseUrl: (string) ($config['base_url'] ?? 'https://itunes.apple.com'),
                limits: $limits,
                timeout: (int) ($config['timeout'] ?? 15),
            ),
            default => throw ConnectorException::of(ConnectorFailure::Misconfigured),
        };
    }

    public function supports(string $platform): bool
    {
        return $this->config($platform) !== null;
    }

    /**
     * @return list<string>
     */
    public function platforms(): array
    {
        /** @var array<string, mixed> $platforms */
        $platforms = config('connectors.platforms', []);

        return array_values(array_keys($platforms));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function config(string $platform): ?array
    {
        $config = config('connectors.platforms.'.$platform);

        return is_array($config) ? $config : null;
    }

    public function retryAfter(string $platform): int
    {
        return (int) ($this->config($platform)['retry_after'] ?? 60);
    }

    /**
     * @return array{max_attempts: int, decay_seconds: int}
     */
    public function rateLimit(string $platform): array
    {
        $limit = $this->config($platform)['rate_limit'] ?? [];

        return [
            'max_attempts' => (int) ($limit['max_attempts'] ?? 60),
            'decay_seconds' => (int) ($limit['decay_seconds'] ?? 60),
        ];
    }

    /**
     * The fixture set is a directory name, so it is whitelisted rather than
     * sanitised: anything outside [A-Za-z0-9_-] is refused instead of being
     * escaped, which closes path traversal without having to reason about it.
     *
     * @param  array<string, mixed>  $settings
     */
    private function fixtureDirectory(array $settings): string
    {
        $set = $settings['fixture_set'] ?? 'default';

        if (! is_string($set) || preg_match('/^[A-Za-z0-9_-]+$/', $set) !== 1) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        return rtrim((string) config('connectors.fixtures_path'), '/\\')
            .DIRECTORY_SEPARATOR.'fixture'
            .DIRECTORY_SEPARATOR.$set;
    }
}
