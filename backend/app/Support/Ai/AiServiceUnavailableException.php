<?php

namespace App\Support\Ai;

use App\Exceptions\ApiException;
use App\Support\Http\ApiErrorCode;
use Throwable;

/**
 * Every way a call to the analyzer can fail, as one type.
 *
 * It extends ApiException so that if it ever escapes into an HTTP request it
 * renders as the catalogued 503 AI_SERVICE_UNAVAILABLE rather than a bare 500.
 * Its normal home, though, is AnalyzeFeedbackJob, where it triggers the
 * exponential backoff of spec 3.5.
 *
 * The named constructors carry a short machine `reason` for logs and for the
 * failed_jobs row. None of them ever carries the analyzer's response body or
 * the feedback text: both are customer PII (invariant I5 reasoning applies to
 * feedback authors just as it does to connector credentials).
 */
final class AiServiceUnavailableException extends ApiException
{
    private function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(ApiErrorCode::AiServiceUnavailable, previous: $previous);

        $this->message = $this->message.' ['.$reason.']';
    }

    /**
     * The request never produced an HTTP response: DNS, connection refused,
     * TLS, or the client-side timeout.
     */
    public static function transport(Throwable $previous): self
    {
        return new self('transport_failure', $previous);
    }

    /**
     * The analyzer answered, but not with 200. `$upstreamCode` is the `code`
     * field of its error envelope when it sent one - a stable machine string,
     * never free text.
     */
    public static function upstreamStatus(int $status, ?string $upstreamCode): self
    {
        return new self('upstream_status_'.$status.($upstreamCode === null ? '' : '_'.$upstreamCode));
    }

    /**
     * The analyzer answered 200 with a body that does not satisfy the contract
     * in contracts/ai-openapi.json.
     *
     * Only the offending field *names* are recorded. The values are model
     * output derived from customer text.
     *
     * @param  list<string>  $fields
     */
    public static function invalidResponse(array $fields): self
    {
        return new self('invalid_response:'.implode(',', $fields));
    }
}
