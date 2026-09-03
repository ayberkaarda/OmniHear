<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Access to the shared `/v1/analyze` contract fixtures, plus the HTTP fakes
 * built out of them.
 *
 * CLAUDE.md section 2: for a shape the fixtures already cover, a test may not
 * treat its own inline JSON as proof. The analyzer's 200 body is such a shape,
 * so every test in this track that needs an analyzer response takes it from
 * contracts/fixtures/analyze/ - the same files
 * ai-service/tests/test_contract_fixtures.py consumes.
 *
 * # The mount
 *
 * The dev compose stack bind-mounts only `../backend` into the backend
 * container, so `contracts/` is not visible from inside it. Until
 * infra/docker-compose.dev.yml gains `- ../contracts:/srv/contracts:ro` (an
 * infra/** file this track may not edit), the suite has to be run with the
 * mount supplied on the command line:
 *
 *   docker compose -f infra/docker-compose.dev.yml run --rm \
 *     -v "$PWD/contracts:/srv/contracts:ro" \
 *     -e DB_DATABASE=test_tmp_f5 backend php artisan test
 *
 * Without it the fixture-backed tests skip and say so rather than falling back
 * to invented JSON.
 *
 * This class lives outside tests/Feature and tests/Unit so neither PHPUnit's
 * testsuite directories nor Pest's `->in('Feature')` binding collect it as a
 * test file.
 */
final class AiServiceFake
{
    /**
     * Candidate locations, most explicit first.
     *
     * @return list<string>
     */
    private static function candidates(): array
    {
        return array_values(array_filter([
            (string) getenv('CONTRACT_FIXTURES_PATH'),
            '/srv/contracts/fixtures/analyze',
            // The repository root, for a checkout whose suite runs outside the
            // container. Computed from __DIR__ rather than base_path(), because
            // Pest evaluates dataset closures before the application is booted
            // and the helper is not available yet at that point.
            dirname(__DIR__, 3).'/contracts/fixtures/analyze',
        ]));
    }

    public static function fixturePath(): ?string
    {
        foreach (self::candidates() as $candidate) {
            if ($candidate !== '' && is_dir($candidate)) {
                return rtrim($candidate, '/');
            }
        }

        return null;
    }

    public static function available(): bool
    {
        return self::fixturePath() !== null;
    }

    public static function skipReason(): string
    {
        return 'contracts/fixtures/analyze is not reachable from this container. '
            .'Run with -v "<repo>/contracts:/srv/contracts:ro" or set CONTRACT_FIXTURES_PATH.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        $path = self::fixturePath();

        if ($path === null) {
            throw new RuntimeException(self::skipReason());
        }

        $file = $path.'/'.$name.'.json';

        if (! is_file($file)) {
            throw new RuntimeException('Missing contract fixture: '.$file);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Every fixture file name, sorted.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $path = self::fixturePath();

        if ($path === null) {
            return [];
        }

        $names = array_map(
            fn (string $file): string => basename($file, '.json'),
            glob($path.'/*.json') ?: [],
        );

        sort($names);

        return array_values($names);
    }

    /**
     * The fixtures that describe a successful single analysis.
     *
     * @return list<string>
     */
    public static function successNames(): array
    {
        return array_values(array_filter(
            self::names(),
            fn (string $name): bool => str_starts_with($name, 'single-'),
        ));
    }

    /**
     * The analyzer's response body from a 200 fixture.
     *
     * @return array<string, mixed>
     */
    public static function successBody(string $name = 'single-bug-report'): array
    {
        /** @var array<string, mixed> $response */
        $response = self::fixture($name)['response'];

        return $response;
    }

    /**
     * A fake analyzer still needs a configured shared secret.
     *
     * AiClient refuses to send when `ai.hmac_secret` is empty (it will not
     * sign with the empty string), and the variable is not set in every
     * environment the suite runs in - a clean checkout outside the compose
     * stack has none. Pinning it here keeps the fake-backed tests about the
     * pipeline instead of about the environment, and leaves
     * LiveAiServiceTest, which never calls these helpers, using the real
     * value it has to share with the running analyzer.
     */
    private static function configureSecret(): void
    {
        config(['ai.hmac_secret' => 'fixture-analyzer-secret']);
    }

    /**
     * Point the HTTP client at a fixture-backed analyzer.
     *
     * @return array<string, mixed> the response body that will be returned
     */
    public static function fakeSuccess(string $name = 'single-bug-report'): array
    {
        $body = self::successBody($name);

        self::configureSecret();

        Http::fake([
            '*/v1/analyze' => Http::response($body, 200),
        ]);

        return $body;
    }

    /**
     * Point the HTTP client at an analyzer that is failing, using the status
     * and error envelope of an error fixture.
     */
    public static function fakeFailure(string $name = 'error-invalid-signature'): void
    {
        $fixture = self::fixture($name);

        self::configureSecret();

        Http::fake([
            '*/v1/analyze' => Http::response($fixture['response'], (int) $fixture['status']),
        ]);
    }
}
