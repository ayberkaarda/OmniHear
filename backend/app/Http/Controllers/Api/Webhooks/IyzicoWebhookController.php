<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiErrorCode;
use App\Support\Payments\Iyzico\IyzicoEventId;
use App\Support\Payments\Iyzico\IyzicoGateway;
use App\Support\Payments\Iyzico\IyzicoSignatureVerifier;
use App\Support\Payments\Iyzico\IyzicoWebhookHandler;
use App\Support\Payments\WebhookPipeline;
use App\Support\Payments\WebhookSignatureException;
use App\Support\Payments\WebhookStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/webhooks/iyzico — unauthenticated by necessity, same as Stripe.
 *
 * The one structural difference: iyzico sends no event id, so `event_id` is
 * derived from the payload before the pipeline can enforce invariant I3. See
 * IyzicoEventId for the scheme and why a replay collapses onto itself while
 * two distinct events do not.
 */
final class IyzicoWebhookController extends Controller
{
    public function __construct(
        private readonly IyzicoSignatureVerifier $verifier,
        private readonly IyzicoWebhookHandler $handler,
        private readonly WebhookPipeline $pipeline,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        try {
            $this->verifier->verify($rawBody, $request->header((string) config('iyzico.signature_header')));
        } catch (WebhookSignatureException) {
            return $this->invalidSignature();
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || $payload === []) {
            return WebhookPipeline::ok(WebhookStatus::IGNORED_MALFORMED);
        }

        return $this->pipeline->process(
            IyzicoGateway::PROVIDER,
            IyzicoEventId::derive($payload),
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
