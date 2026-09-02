<?php

use App\Support\Ai\AiClient;
use App\Support\Ai\AiServiceUnavailableException;
use App\Support\Ai\AnalysisResult;
use Tests\Support\AiServiceFake;

/**
 * The fixture contract test proves the two schemas agree. This one proves the
 * two *implementations* agree, which is a strictly stronger claim and the only
 * way to catch the failure mode a fixture cannot: a signature computed over
 * bytes other than the ones transmitted. A fixture round trip is symmetric -
 * the same client both signs and verifies - so it would pass while the real
 * analyzer answered 401.
 *
 * Kept apart from AnalyzeContractTest on purpose, and skipped when the analyzer
 * is not running, so the gate does not depend on a live service. When it does
 * run, no HTTP fake is installed anywhere in this file: these are real requests
 * to http://ai-service:8001 over the compose network.
 */
function liveAnalyzerReachable(): bool
{
    $base = rtrim((string) config('ai.base_url'), '/');
    $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $body = @file_get_contents($base.'/health', false, $context);

    return is_string($body) && str_contains($body, 'model_version');
}

beforeEach(function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }

    if (! liveAnalyzerReachable()) {
        $this->markTestSkipped('The analyzer at '.config('ai.base_url').' is not reachable.');
    }

    if ((string) config('ai.hmac_secret') === '') {
        $this->markTestSkipped('AI_SERVICE_HMAC_SECRET is not set in this environment.');
    }
});

it('gets a contract-valid analysis out of the running analyzer', function (string $name) {
    $fixture = AiServiceFake::fixture($name);

    $result = app(AiClient::class)->analyze(
        $fixture['request']['text'],
        $fixture['request']['language_hint'] ?? null,
        $fixture['correlation_id'],
    );

    // Shape, bounds and enum membership only - the scores belong to whichever
    // backend this checkout is running (ONNX or the lexicon fallback), and
    // contracts/fixtures/analyze/README.md marks them as illustrative.
    expect($result)->toBeInstanceOf(AnalysisResult::class)
        ->and($result->sentimentLabel)->toBeIn(AnalysisResult::SENTIMENT_LABELS)
        ->and($result->category)->toBeIn(AnalysisResult::CATEGORIES)
        ->and($result->modelVersion)->not->toBe('')
        ->and($result->correlationId)->toBe($fixture['correlation_id']);
})->with(['single-bug-report', 'single-tr-complaint', 'single-en-praise']);

it('is rejected by the live analyzer when the signature does not match the body', function () {
    // Invariant I7 from the other direction: the analyzer is the thing
    // enforcing the signature, not a mock of it.
    $client = new AiClient((string) config('ai.base_url'), 'a-secret-the-analyzer-does-not-share', 5);

    expect(fn () => $client->analyze('anything', null, '11111111-1111-1111-1111-111111111111'))
        ->toThrow(AiServiceUnavailableException::class);
});
