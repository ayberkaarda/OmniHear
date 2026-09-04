<?php

namespace App\Support\Ai;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * The analyzer's liveness and build identity.
     *
     * Two deliberate differences from analyze():
     *
     * 1. **No signature.** The route is unauthenticated on the analyzer side -
     *    ai-service/app/routers/health.py declares no `verify_signature`
     *    dependency, unlike both /v1/analyze routes - so a signature would be
     *    ignored. It would also make the probe depend on a configured shared
     *    secret, and the moment an operator most needs this endpoint is the
     *    moment the configuration is wrong. Nothing leaves the process here
     *    but a GET with no body, so there is nothing for a signature to bind.
     * 2. **The path is `/health`, not `/v1/health`.** Only the analyze routes
     *    are versioned; see contracts/ai-openapi.json, which is generated from
     *    the running application.
     *
     * The response shape is contractual enough to be validated: the reprocess
     * workflow (App\Console\Commands\ReprocessAnalysesCommand) selects rows on
     * `model_version`, so a health body without one must fail loudly rather
     * than let the command compare every stored analysis against null and
     * re-queue the entire table.
     *
     * @return array{status: string, service: string, model_version: string, sentiment_backend: string}
     *
     * @throws AiServiceUnavailableException
     */
    public function health(?string $correlationId = null): array
    {
        $correlationId ??= (string) Str::uuid();
        $path = '/health';

        $response = $this->perform(
            $path,
            $correlationId,
            fn (): Response => Http::withHeaders([
                'X-Correlation-Id' => $correlationId,
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->get($this->baseUrl.$path),
        );

        return $this->healthPayload($response);
    }

    /**
     * @return array{status: string, service: string, model_version: string, sentiment_backend: string}
     *
     * @throws AiServiceUnavailableException
     */
    private function healthPayload(Response $response): array
    {
        $body = (array) $response->json();
        $payload = [];
        $missing = [];

        foreach (['status', 'service', 'model_version', 'sentiment_backend'] as $field) {
            $value = $body[$field] ?? null;

            if (! is_string($value) || $value === '') {
                $missing[] = $field;

                continue;
            }

            $payload[$field] = $value;
        }

        if ($missing !== []) {
            throw AiServiceUnavailableException::invalidResponse($missing);
        }

        /** @var array{status: string, service: string, model_version: string, sentiment_backend: string} $payload */
        return $payload;
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
        // Fail closed before anything leaves the process. `config/ai.php`
        // falls back to an empty string when AI_SERVICE_HMAC_SECRET is unset,
        // and hash_hmac() is perfectly happy to key on '' - so a
        // misconfigured backend would have gone on posting customer feedback
        // to whatever host `ai.base_url` names, signed with a key that is not
        // a secret. Refusing to send is the only safe reading of an absent
        // shared secret (invariant I7).
        if (trim($this->secret) === '') {
            Log::error('ai.request_refused', [
                'correlation_id' => $correlationId,
                'path' => $path,
                'reason' => 'signing_secret_not_configured',
            ]);

            throw AiServiceUnavailableException::signingSecretNotConfigured();
        }

        // The bytes that get signed and the bytes that get sent are this exact
        // string. JSON_UNESCAPED_UNICODE keeps Turkish text out of \uXXXX
        // escapes, which matters only for size - the signature covers whatever
        // encoding is chosen, as long as it is chosen once.
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return $this->perform(
            $path,
            $correlationId,
            fn (): Response => Http::withHeaders([
                'X-Signature' => $this->sign($body),
                'X-Correlation-Id' => $correlationId,
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->withBody($body, 'application/json')
                ->post($this->baseUrl.$path),
        );
    }

    /**
     * Transport, logging and error mapping - the part every call shares.
     *
     * Kept in one place so a second endpoint cannot quietly acquire different
     * failure semantics from analyze(): the same ConnectionException mapping,
     * the same PII-free log line, the same non-2xx to
     * AiServiceUnavailableException translation.
     *
     * @param  Closure(): Response  $send
     *
     * @throws AiServiceUnavailableException
     */
    private function perform(string $path, string $correlationId, Closure $send): Response
    {
        $started = microtime(true);

        try {
            $response = $send();
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
