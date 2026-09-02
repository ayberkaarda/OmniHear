<?php

namespace App\Support\Payments;

use App\Models\Company;
use App\Models\User;

/**
 * The seam that makes payments testable without a live provider account.
 *
 * Neither Stripe nor iyzico has a usable account in this project, so every
 * outbound call goes through an implementation of this interface and every
 * test drives it with `Http::fake()` against recorded fixtures under
 * tests/Fixtures/webhooks/{stripe,iyzico}/.
 */
interface PaymentGateway
{
    /** 'stripe' | 'iyzico' */
    public function provider(): string;

    /**
     * @param  string  $plan  Plan key from config/quota.php, e.g. 'pro'.
     *
     * @throws PaymentProviderException when the provider rejects the call, is
     *                                  unreachable, or answers with a shape we
     *                                  cannot use. Surfaces as 502
     *                                  PAYMENT_PROVIDER_ERROR.
     */
    public function createCheckoutSession(Company $company, User $user, string $plan): CheckoutSession;
}
