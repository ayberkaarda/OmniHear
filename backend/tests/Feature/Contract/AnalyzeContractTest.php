<?php

use App\Support\Ai\AiClient;
use App\Support\Ai\AiServiceUnavailableException;
use App\Support\Ai\AnalysisResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiServiceFake;

/**
 * Invariant I7: the Laravel and FastAPI halves of `/v1/analyze` agree.
 *
 * Both sides consume the *same files*: ai-service/tests/test_contract_fixtures.py
 * validates them against the Pydantic models, this file validates them against
 * App\Support\Ai\AnalysisResult. No inline JSON appears anywhere in here, which
 * is the point - a fixture that only one side reads proves nothing.
 *
 * Only the normative parts of a fixture are asserted, per
 * contracts/fixtures/analyze/README.md: the key set, the status, the error
 * `code`, the enum membership and the declared bounds. The scores, keywords and
 * the model_version string are model output and will move when the model is
 * retrained; asserting equality on them would make a retrain look like a
 * contract break.
 */
beforeEach(function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }
});

it('finds the shared fixture directory', function () {
    expect(AiServiceFake::names())->not->toBeEmpty()
        ->and(AiServiceFake::names())->toContain('single-bug-report', 'error-invalid-signature');
});

it('accepts every successful analyze fixture', function (string $name) {
    $fixture = AiServiceFake::fixture($name);

    expect($fixture['endpoint'])->toBe('/v1/analyze')
        ->and($fixture['status'])->toBe(200);

    $result = AnalysisResult::fromResponse($fixture['response']);

    expect($result->sentimentLabel)->toBeIn(AnalysisResult::SENTIMENT_LABELS)
        ->and($result->category)->toBeIn(AnalysisResult::CATEGORIES)
        ->and($result->sentimentScore)->toBeGreaterThanOrEqual(-1.0)
        ->and($result->sentimentScore)->toBeLessThanOrEqual(1.0)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.0)
        ->and($result->confidence)->toBeLessThanOrEqual(1.0)
        ->and($result->keywords)->toBeArray()
        ->and(count($result->keywords))->toBeLessThanOrEqual(AnalysisResult::MAX_KEYWORDS)
        ->and(strlen($result->language))->toBe(2)
        ->and($result->modelVersion)->not->toBe('');
})->with(fn () => AiServiceFake::successNames());

it('accepts every item of the batch fixture response', function () {
    $fixture = AiServiceFake::fixture('batch-fifty-items');

    expect($fixture['endpoint'])->toBe('/v1/analyze/batch')
        ->and($fixture['request']['items'])->toHaveCount(50)
        ->and($fixture['response']['results'])->toHaveCount(50);

    foreach ($fixture['response']['results'] as $item) {
        $result = AnalysisResult::fromResponse($item);

        expect($result->sentimentLabel)->toBeIn(AnalysisResult::SENTIMENT_LABELS)
            ->and($result->category)->toBeIn(AnalysisResult::CATEGORIES);
    }
});

it('keeps the emoji-only edge case within the contract', function () {
    // The one fixture whose *content* is normative: no letters means no
    // keywords, and a client that assumed a non-empty list would break on it.
    $result = AnalysisResult::fromResponse(AiServiceFake::successBody('single-edge-emoji-only'));

    expect($result->keywords)->toBe([]);
});

it('rejects a response whose sentiment_score leaves the declared bounds', function () {
    // Derived from a fixture rather than invented: the same body, with the one
    // field under test pushed out of range.
    $body = AiServiceFake::successBody('single-en-praise');
    $body['sentiment_score'] = 1.5;

    expect(fn () => AnalysisResult::fromResponse($body))
        ->toThrow(AiServiceUnavailableException::class);
});

it('rejects a response whose category is outside the shared enum', function () {
    $body = AiServiceFake::successBody('single-bug-report');
    $body['category'] = 'not_a_category';

    expect(fn () => AnalysisResult::fromResponse($body))
        ->toThrow(AiServiceUnavailableException::class);
});

it('names only the offending field, never its value, when a response is rejected', function () {
    $body = AiServiceFake::successBody('single-bug-report');
    $body['confidence'] = 42.0;

    try {
        AnalysisResult::fromResponse($body);
        $this->fail('Expected the invalid response to be rejected.');
    } catch (AiServiceUnavailableException $e) {
        expect($e->reason)->toBe('invalid_response:confidence')
            ->and($e->getMessage())->not->toContain('42');
    }
});

it('signs the exact bytes it puts on the wire', function () {
    $fixture = AiServiceFake::fixture('single-bug-report');
    AiServiceFake::fakeSuccess('single-bug-report');

    $secret = 'contract-test-shared-value';
    $client = new AiClient('http://ai-service:8001', $secret, 5);

    $client->analyze($fixture['request']['text'], null, $fixture['correlation_id']);

    Http::assertSent(function ($request) use ($secret, $fixture) {
        // This is the algorithm in ai-service/app/security.py, recomputed here
        // against the body the client actually transmitted. If the client ever
        // re-serialises the payload between signing and sending, the two stop
        // matching - which is exactly the 401 the live analyzer would return.
        $expected = hash_hmac('sha256', $request->body(), $secret);

        return $request->url() === 'http://ai-service:8001/v1/analyze'
            && $request->header('X-Signature')[0] === $expected
            && $request->header('X-Correlation-Id')[0] === $fixture['correlation_id']
            && json_decode($request->body(), true) === $fixture['request'];
    });
});

it('carries the language hint only when there is one', function () {
    $fixture = AiServiceFake::fixture('single-tr-complaint');
    AiServiceFake::fakeSuccess('single-tr-complaint');

    $client = new AiClient('http://ai-service:8001', 'contract-test-shared-value', 5);
    $client->analyze($fixture['request']['text'], 'tr', $fixture['correlation_id']);

    Http::assertSent(fn ($request) => json_decode($request->body(), true) === [
        'text' => $fixture['request']['text'],
        'language_hint' => 'tr',
    ]);
});

it('turns every analyzer error fixture into AI_SERVICE_UNAVAILABLE', function (string $name) {
    $fixture = AiServiceFake::fixture($name);
    AiServiceFake::fakeFailure($name);

    $client = new AiClient('http://ai-service:8001', 'contract-test-shared-value', 5);

    try {
        $client->analyze('any body at all', null, $fixture['correlation_id']);
        $this->fail('Expected the analyzer error to surface as an exception.');
    } catch (AiServiceUnavailableException $e) {
        expect($e->status())->toBe(503)
            ->and($e->errorCode->value)->toBe('AI_SERVICE_UNAVAILABLE')
            // The upstream machine code is preserved for the operator; only
            // `code` is contractual, `message` is not.
            ->and($e->reason)->toBe(
                'upstream_status_'.$fixture['status'].'_'.$fixture['response']['code']
            );
    }
})->with(['error-invalid-signature', 'error-validation-error', 'error-batch-too-large']);

it('reports a transport failure as AI_SERVICE_UNAVAILABLE', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7'));

    $client = new AiClient('http://ai-service:8001', 'contract-test-shared-value', 1);

    try {
        $client->analyze('anything', null, 'c-1');
        $this->fail('Expected a transport failure to surface as an exception.');
    } catch (AiServiceUnavailableException $e) {
        expect($e->reason)->toBe('transport_failure')
            ->and($e->status())->toBe(503);
    }
});
