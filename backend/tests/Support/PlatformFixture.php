<?php

namespace Tests\Support;

/**
 * Access to the recorded platform responses under tests/Fixtures/platforms.
 *
 * Everything here returns raw bytes or a decoded array. Nothing hard-codes a
 * review's text, author or id: the App Store fixtures hold real captured
 * content that is being replaced with synthetic values, and a test that asserts
 * on a specific reviewer's name would break on that swap while proving nothing
 * about the connector. Assertions derive their expectations from the fixture at
 * run time, so they test the shape, which is what actually has to hold.
 */
final class PlatformFixture
{
    public static function path(string $platform, string $file): string
    {
        return rtrim((string) config('connectors.fixtures_path'), '/\\')
            .DIRECTORY_SEPARATOR.$platform
            .DIRECTORY_SEPARATOR.$file;
    }

    public static function raw(string $platform, string $file): string
    {
        $contents = file_get_contents(self::path($platform, $file));

        if ($contents === false) {
            throw new \RuntimeException("Missing platform fixture: {$platform}/{$file}");
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $platform, string $file): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::raw($platform, $file), true, 64, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The `entry` list of a captured App Store feed page, or [] when the page
     * came back empty.
     *
     * @return list<array<string, mixed>>
     */
    public static function appStoreEntries(string $file): array
    {
        $entries = self::json('appstore', $file)['feed']['entry'] ?? [];

        return is_array($entries) ? array_values($entries) : [];
    }
}
