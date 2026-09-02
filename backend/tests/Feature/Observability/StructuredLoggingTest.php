<?php

use App\Http\Middleware\CorrelationId;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

/*
|--------------------------------------------------------------------------
| Spec 3.6 — structured logging with a correlation id
|--------------------------------------------------------------------------
|
| The correlation id already travelled between Laravel and the FastAPI service,
| but no log line was machine-readable, so it could not be queried on either
| side. These tests assert the shape of a real written line, not the config
| array: a channel that resolves is not the same as a channel that emits JSON.
|
*/

/**
 * Point the json channel at a temp file and return everything written to it.
 *
 * @return list<array<string, mixed>>
 */
function jsonLogLines(Closure $callback): array
{
    $path = tempnam(sys_get_temp_dir(), 'omnihear-log-').'.json';

    config()->set('logging.channels.json.handler_with.stream', $path);
    Log::forgetChannel('json');

    try {
        $callback();

        $raw = is_file($path) ? (string) file_get_contents($path) : '';

        return collect(explode("\n", trim($raw)))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    } finally {
        Log::forgetChannel('json');

        if (is_file($path)) {
            unlink($path);
        }
    }
}

it('makes the json channel the default', function () {
    expect(config('logging.default'))->toBe('json');
});

it('wires the json channel to a json formatter on a stream', function () {
    $handlers = Log::channel('json')->getLogger()->getHandlers();

    expect($handlers)->toHaveCount(1)
        ->and($handlers[0])->toBeInstanceOf(StreamHandler::class)
        ->and($handlers[0]->getFormatter())->toBeInstanceOf(JsonFormatter::class);
});

it('writes one parseable json object per line', function () {
    $lines = jsonLogLines(function (): void {
        Log::channel('json')->info('first.event', ['a' => 1]);
        Log::channel('json')->warning('second.event', ['b' => 2]);
    });

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['message'])->toBe('first.event')
        ->and($lines[0]['level_name'])->toBe('INFO')
        ->and($lines[0]['context'])->toBe(['a' => 1])
        ->and($lines[1]['level_name'])->toBe('WARNING')
        ->and($lines[0])->toHaveKey('datetime');
});

it('carries the correlation id, the tenant and the user on a request log line', function () {
    [$company, $user] = tenant();

    $lines = jsonLogLines(function () use ($user): void {
        $this->actingAs($user, 'sanctum')
            ->withHeader(CorrelationId::HEADER, 'corr-abc-123')
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        // Logged after the request so it observes the context the middleware
        // established, exactly as any application log line inside it would.
        Log::channel('json')->info('probe.after_request');
    });

    $line = collect($lines)->firstWhere('message', 'probe.after_request');

    expect($line)->not->toBeNull()
        ->and($line['context']['correlation_id'])->toBe('corr-abc-123')
        ->and((int) $line['context']['company_id'])->toBe($company->id)
        ->and((int) $line['context']['user_id'])->toBe($user->id);
});

it('leaves the tenant off the context when nobody is authenticated', function () {
    $lines = jsonLogLines(function (): void {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);

        Log::channel('json')->info('probe.anonymous');
    });

    $line = collect($lines)->firstWhere('message', 'probe.anonymous');

    expect($line['context'])->toHaveKey('correlation_id')
        ->and($line['context'])->not->toHaveKey('company_id')
        ->and($line['context'])->not->toHaveKey('user_id');
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — nothing sensitive reaches a line
|--------------------------------------------------------------------------
*/

it('keeps credentials, payloads and PII out of the log context', function () {
    [$company, $user] = tenant();

    $integration = asTenant($company, fn () => Integration::factory()->for($company)->create([
        'credentials' => ['api_key' => 'super-secret-key-value'],
    ]));

    $lines = jsonLogLines(function () use ($user, $integration): void {
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/integrations/{$integration->id}")
            ->assertOk();

        Log::channel('json')->info('probe.secrecy');
    });

    $rendered = json_encode($lines);

    expect($rendered)->not->toContain('super-secret-key-value')
        ->and($rendered)->not->toContain($user->email)
        ->and($rendered)->not->toContain('raw_payload');
});

it('stamps only ids on the shared context, never a name or an address', function () {
    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

    $context = Log::sharedContext();

    expect(array_keys($context))->toBe(['correlation_id', 'company_id', 'user_id'])
        ->and(json_encode($context))->not->toContain($user->email)
        ->and(json_encode($context))->not->toContain($user->name);
});
