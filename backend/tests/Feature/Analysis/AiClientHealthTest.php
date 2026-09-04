<?php

use App\Support\Ai\AiClient;
use App\Support\Ai\AiServiceUnavailableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * AiClient::health() — the probe the reprocess workflow reads model_version
 * from (App\Console\Commands\ReprocessAnalysesCommand).
 *
 * # The fake shape used here, and why it is not two Http::fake() calls
 *
 * docs/LESSONS.md, 2026-09-03: `Http::fake()` *merges* stub callbacks rather
 * than replacing them, so a second `Http::fake(closure)` inside a test that
 * already installed one leaves the first closure in charge. A two-phase test
 * written that way never reaches its second phase and stays green while
 * proving nothing — it cost both W8 connector tracks a debugging pass.
 *
 * Every multi-phase test below therefore installs exactly one closure, driven
 * by a mutable script, and asserts the request count so a phase that was never
 * reached fails loudly instead of silently.
 */

/**
 * @return array{status: string, service: string, model_version: string, sentiment_backend: string}
 */
function healthBody(array $overrides = []): array
{
    return array_merge([
        'status' => 'ok',
        'service' => 'ai-service',
        'model_version' => 'omnihear-lexicon-0123456789ab',
        'sentiment_backend' => 'lexicon',
    ], $overrides);
}

function healthUrl(): string
{
    return rtrim((string) config('ai.base_url'), '/').'/health';
}

it('returns the analyzer status, service, model version and sentiment backend', function () {
    Http::fake([healthUrl() => Http::response(healthBody(), 200)]);

    expect(app(AiClient::class)->health())->toBe(healthBody());
});

it('calls GET /health, which is unversioned, and sends no signature', function () {
    // Not /v1/health: contracts/ai-openapi.json, generated from the running
    // analyzer, versions only the analyze routes.
    Http::fake([healthUrl() => Http::response(healthBody(), 200)]);

    app(AiClient::class)->health('22222222-2222-2222-2222-222222222222');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === healthUrl()
        // ai-service/app/routers/health.py declares no verify_signature
        // dependency; a signature here would be decoration.
        && $request->header('X-Signature') === []
        && $request->header('X-Correlation-Id')[0] === '22222222-2222-2222-2222-222222222222');
});

it('generates a correlation id when the caller supplies none', function () {
    Http::fake([healthUrl() => Http::response(healthBody(), 200)]);

    app(AiClient::class)->health();

    Http::assertSent(function (Request $request) {
        $id = $request->header('X-Correlation-Id')[0] ?? '';

        return preg_match('/^[0-9a-f-]{36}$/', $id) === 1;
    });
});

it('probes even when the shared secret is not configured', function () {
    // analyze() fails closed without a secret, deliberately. health() must not:
    // an unset AI_SERVICE_HMAC_SECRET is precisely the incident an operator
    // reaches for this endpoint to diagnose, and nothing signed leaves here.
    config(['ai.hmac_secret' => '']);
    Http::fake([healthUrl() => Http::response(healthBody(), 200)]);

    expect(app(AiClient::class)->health()['model_version'])->toBe('omnihear-lexicon-0123456789ab');
    Http::assertSentCount(1);
});

it('maps a non-2xx answer to AI_SERVICE_UNAVAILABLE', function () {
    Http::fake([healthUrl() => Http::response(['code' => 'INTERNAL_ERROR'], 500)]);

    expect(fn () => app(AiClient::class)->health())
        ->toThrow(AiServiceUnavailableException::class);
});

it('rejects a 200 whose body is missing a contract field', function () {
    // A health body without model_version would otherwise make the reprocess
    // command compare every stored analysis against nothing.
    Http::fake([healthUrl() => Http::response(['status' => 'ok', 'service' => 'ai-service'], 200)]);

    try {
        app(AiClient::class)->health();
        $this->fail('Expected AiServiceUnavailableException.');
    } catch (AiServiceUnavailableException $e) {
        expect($e->reason)->toBe('invalid_response:model_version,sentiment_backend');
    }
});

it('recovers on a later probe after the analyzer was down', function () {
    // One closure, one script, and an asserted request count: the shape
    // docs/LESSONS.md prescribes. Written as two Http::fake() calls, the
    // second stub would be merged behind the first and the recovery phase
    // below would silently never run.
    $script = [
        Http::response(['code' => 'INTERNAL_ERROR'], 503),
        Http::response(healthBody(['sentiment_backend' => 'onnx']), 200),
    ];
    $calls = 0;

    Http::fake(function () use (&$script, &$calls) {
        $calls++;

        return array_shift($script);
    });

    $client = app(AiClient::class);

    expect(fn () => $client->health())->toThrow(AiServiceUnavailableException::class);

    expect($client->health()['sentiment_backend'])->toBe('onnx')
        // Proof the second phase was reached rather than answered by the
        // first stub.
        ->and($calls)->toBe(2);

    Http::assertSentCount(2);
});
