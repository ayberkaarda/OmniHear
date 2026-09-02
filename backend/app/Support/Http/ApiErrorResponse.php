<?php

namespace App\Support\Http;

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single rendering path for every non-2xx /api/v1 response.
 *
 * Shape: {code, message} plus {errors} for VALIDATION_ERROR.
 * See docs/contracts/http-api-v1.md section 2.
 */
class ApiErrorResponse
{
    /**
     * Returns null when the request is not an API request, letting the default
     * Laravel handler take over (web routes, the /api/health probe).
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ApiException => self::build($e->errorCode, $e->errors, self::retryHeaders($e->retryAfter)),
            $e instanceof ValidationException => self::build(ApiErrorCode::ValidationError, $e->errors()),
            $e instanceof AuthenticationException => self::build(ApiErrorCode::Unauthenticated),
            $e instanceof AuthorizationException => self::fromAuthorization($e),
            $e instanceof ModelNotFoundException => self::build(ApiErrorCode::NotFound),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => self::unhandled($e),
        };
    }

    /**
     * Cross-tenant access resolves to 404, never 403: a 403 confirms the row
     * exists (invariant I1). Policies signal that with denyAsNotFound().
     */
    private static function fromAuthorization(AuthorizationException $e): JsonResponse
    {
        return self::build($e->status() === 404 ? ApiErrorCode::NotFound : ApiErrorCode::Forbidden);
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $headers = array_intersect_key($e->getHeaders(), ['Retry-After' => true]);

        return self::build(ApiErrorCode::fromStatus($e->getStatusCode()), [], $headers, $e->getStatusCode());
    }

    private static function unhandled(Throwable $e): JsonResponse
    {
        $message = config('app.debug')
            ? $e->getMessage()
            : ApiErrorCode::ServerError->message();

        return response()->json([
            'code' => ApiErrorCode::ServerError->value,
            'message' => $message,
        ], 500);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    private static function build(
        ApiErrorCode $code,
        array $errors = [],
        array $headers = [],
        ?int $status = null,
    ): JsonResponse {
        $payload = [
            'code' => $code->value,
            'message' => $code->message(),
        ];

        if ($code === ApiErrorCode::ValidationError && $errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status ?? $code->status(), $headers);
    }

    /**
     * @return array<string, string>
     */
    private static function retryHeaders(?int $retryAfter): array
    {
        return $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter];
    }
}
