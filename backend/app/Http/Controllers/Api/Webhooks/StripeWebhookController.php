<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiErrorCode;
use App\Support\Payments\Stripe\StripeGateway;
use App\Support\Payments\Stripe\StripeSignatureVerifier;
use App\Support\Payments\Stripe\StripeWebhookHandler;
use App\Support\Payments\WebhookPipeline;
use App\Support\Payments\WebhookSignatureException;
use App\Support\Payments\WebhookStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/webhooks/stripe — unauthenticated by necessity.
 *
 * The caller is Stripe, not a tenant, so there is no bearer token and no
 * `SetTenantContext`; the signature is the authentication (spec 7.6). The
 * route also sits outside `/api/v1`, which means ApiErrorResponse does not
 * render for it and the 400 below is built here by hand.
 */
final class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeSignatureVerifier $verifier,
        private readonly StripeWebhookHandler $handler,
        private readonly WebhookPipeline $pipeline,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // getContent(), never $request->all(): the signature covers the exact
        // bytes Stripe sent, and Laravel's decoded input is a different string.
        $rawBody = $request->getContent();

        try {
            $this->verifier->verify($rawBody, $request->header(StripeSignatureVerifier::HEADER));
        } catch (WebhookSignatureException) {
            return $this->invalidSignature();
        }

        $payload = json_decode($rawBody, true);

        // Signed but unusable. 200, not 400: only the holder of the signing
        // secret could have produced this, so it is our bug or a Stripe change,
        // and neither is fixed by making Stripe retry it three more times.
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || $payload['id'] === '') {
            return WebhookPipeline::ok(WebhookStatus::IGNORED_MALFORMED);
        }

        return $this->pipeline->process(
            StripeGateway::PROVIDER,
            $payload['id'],
            $payload,
            fn (): string => $this->handler->handle($payload),
        );
    }

    private function invalidSignature(): JsonResponse
    {
        $code = ApiErrorCode::InvalidWebhookSignature;

        return response()->json([
            'code' => $code->value,
            'message' => $code->message(),
        ], $code->status());
    }
}
