<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The Laravel half of the `/v1/analyze` contract (invariant I7).
 *
 * Two things about this class are load bearing:
 *
 * 1. **The signature covers the exact bytes on the wire.** The body is encoded
 *    once, signed, and handed to `withBody()` verbatim. Letting the HTTP client
 *    re-encode the payload (`->post($url, $array)`) would produce different
 *    bytes for the same data - different key order, different escaping - and
 *    ai-service/app/security.py, which HMACs the raw body it received, would
 *    answer 401. Verified by hand against the running analyzer.
 *
 * 2. **The correlation id is caller-supplied.** It is the same id the inbound
 *    HTTP request carried (App\Http\Middleware\CorrelationId), travelling
 *    across the service boundary so one identifier ties the Laravel log line,
 *    the queue job and the FastAPI log line together (spec 3.6).
 *
 * Retries are deliberately *not* implemented here. Spec 3.5 puts them on the
 * queue job, where a backoff is a cheap sleep in the scheduler rather than an
 * expensive one that pins a worker process.
 */
class AiClient
{
    private readonly string $baseUrl;

    private readonly string $secret;

    private readonly int $timeout;

    public function __construct(?string $baseUrl = null, ?string $secret = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('ai.base_url'), '/');
        $this->secret = $secret ?? (string) config('ai.hmac_secret');
        $this->timeout = $timeout ?? (int) config('ai.timeout');
    }

    /**
     * @throws AiServiceUnavailableException
     */
    public function analyze(string $text, ?string $languageHint, string $correlationId): AnalysisResult
    {
        $payload = ['text' => $text];

        if ($languageHint !== null && $languageHint !== '') {
            $payload['language_hint'] = $languageHint;
        }

        $response = $this->send('/v1/analyze', $payload, $correlationId);

        return AnalysisResult::fromResponse((array) $response->json());
    }

    /**
     * Sign and send one request.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws AiServiceUnavailableException
     */
    private function send(string $path, array $payload, string $correlationId): Response
    {
        // The bytes that get signed and the bytes that get sent are this exact
        // string. JSON_UNESCAPED_UNICODE keeps Turkish text out of \uXXXX
        // escapes, which matters only for size - the signature covers whatever
        // encoding is chosen, as long as it is chosen once.
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $started = microtime(true);

        try {
            $response = Http::withHeaders([
                'X-Signature' => $this->sign($body),
                'X-Correlation-Id' => $correlationId,
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->withBody($body, 'application/json')
                ->post($this->baseUrl.$path);
        } catch (ConnectionException $e) {
            Log::warning('ai.request_failed', [
                'correlation_id' => $correlationId,
                'path' => $path,
                'reason' => 'transport_failure',
            ]);

            throw AiServiceUnavailableException::transport($e);
        }

        // Only the status, the upstream machine code and the duration are
        // logged. The request text is customer feedback and the response is
        // derived from it; neither belongs in a log line.
        Log::info('ai.request_completed', [
            'correlation_id' => $correlationId,
            'path' => $path,
            'status' => $response->status(),
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        if (! $response->successful()) {
            throw AiServiceUnavailableException::upstreamStatus(
                $response->status(),
                $this->upstreamCode($response),
            );
        }

        return $response;
    }

    /**
     * HMAC-SHA256, hex encoded, over the raw body - byte for byte what
     * ai-service/app/security.py recomputes.
     */
    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->secret);
    }

    /**
     * The analyzer's uniform error envelope is {code, message, correlation_id}.
     * Only `code` is contractual (contracts/fixtures/analyze/README.md), so
     * only `code` is read.
     */
    private function upstreamCode(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) ? $code : null;
    }
}
