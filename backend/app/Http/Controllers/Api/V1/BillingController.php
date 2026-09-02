<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\Payments\PaidPlans;
use App\Support\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The tenant-facing half of payments: what am I on, and how do I upgrade.
 *
 * Shapes are fixed by docs/contracts/wave2-seams.md section 3.
 */
final class BillingController extends Controller
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * GET /api/v1/billing/subscription
     */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Subscription::class);

        $company = $request->user()->company;

        // CompanyScope constrains this to the caller's tenant; another
        // company's subscription is not merely hidden from the response, it is
        // not in the result set at all (invariant I1). Newest wins, because a
        // company that switched provider keeps the historical row.
        $subscription = Subscription::query()->latest('id')->first();

        return response()->json([
            'subscription' => $subscription === null ? null : $this->serialize($subscription),
            // Read from the company, not from the subscription: the plan a
            // company is *on* is raised by F5's SubscriptionActivated listener
            // together with the quota limit, so this stays the single source of
            // truth even in the window between activation and that listener.
            'plan' => $company->plan,
            'quota' => [
                'limit' => $company->quota_limit,
                'used' => $company->analyzed_feedback_count,
                'remaining' => $company->quotaRemaining(),
            ],
        ]);
    }

    /**
     * POST /api/v1/billing/checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        Gate::authorize('create', Subscription::class);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(Subscription::PROVIDERS)],
            'plan' => ['required', 'string', Rule::in(PaidPlans::all())],
        ]);

        // A PaymentProviderException from here renders as 502
        // PAYMENT_PROVIDER_ERROR through ApiErrorResponse; nothing is written
        // locally, so a failed checkout leaves no half-finished row behind.
        $session = $this->gateways->for($validated['provider'])->createCheckoutSession(
            $request->user()->company,
            $request->user(),
            $validated['plan'],
        );

        return response()->json($session->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'provider' => $subscription->provider,
            'plan' => $subscription->plan,
            'status' => $subscription->status,
            'current_period_start' => $subscription->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'canceled_at' => $subscription->canceled_at?->toIso8601String(),
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];
    }
}
