<?php

namespace App\Support\OpenApi;

/**
 * Where `contracts/http-api-v1.json` lives and how it is serialized.
 *
 * Kept apart from the generator so the console command and the drift test agree
 * on both by construction rather than by both remembering the same flags — a
 * differing `JSON_PRETTY_PRINT` would make the test fail on formatting and read
 * as a contract change.
 *
 * Sorted keys and a trailing newline, exactly like
 * ai-service/scripts/export_openapi.py: two teams read this file, and a diff
 * that reorders members on every export is a diff nobody reads.
 */
final class OpenApiContract
{
    public const FILENAME = 'http-api-v1.json';

    /**
     * Candidate locations, most explicit first — the same arrangement as
     * Tests\Support\AiServiceFake, and for the same reason: the compose stack
     * mounts the repository's contracts/ at /srv/contracts, while a checkout
     * running outside the container has it beside backend/.
     */
    public static function path(): string
    {
        $fromEnv = getenv('HTTP_CONTRACT_PATH');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        // contracts/ sits beside backend/ in the repository, and the compose
        // stack preserves that: the backend is mounted at /srv/backend and the
        // contracts at /srv/contracts, so one expression covers both.
        return dirname(base_path()).'/contracts/'.self::FILENAME;
    }

    /**
     * The committed document, with line endings normalised, or null when it is
     * not there.
     *
     * The repository root sets `* text=auto`, so a Windows checkout gets this
     * file back with CRLF while the generator always emits LF — comparing the
     * raw bytes would make the drift test fail on every Windows clone and say
     * "the contract is stale" when nothing had changed. Python's `read_text()`
     * does this translation implicitly, which is why the ai-service drift test
     * never had to say so; PHP does not.
     */
    public static function read(?string $path = null): ?string
    {
        $path ??= self::path();

        if (! is_file($path)) {
            return null;
        }

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function serialize(array $document): string
    {
        return json_encode(
            self::sorted($document),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    /**
     * Recursively sort object keys, leaving lists in their own order.
     *
     * `(object) []` survives as an empty object rather than collapsing into an
     * empty array, which in JSON Schema is the difference between "any shape"
     * and a type error.
     */
    private static function sorted(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;

            if ($value === []) {
                return new \stdClass;
            }
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => self::sorted($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => self::sorted($item), $value);
    }
}
