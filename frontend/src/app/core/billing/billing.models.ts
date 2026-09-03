/**
 * Billing shapes — `docs/contracts/wave2-seams.md` section 3 (F6/F7).
 *
 * `GET /billing/subscription` -> `200 {subscription, plan, quota}`
 * `POST /billing/checkout`    -> `200 {provider, checkout_url, session_id}`
 */
import { CompanyPlan } from '../auth/auth.models';

/** `Subscription::PROVIDERS` in the backend model. */
export const PAYMENT_PROVIDERS = ['stripe', 'iyzico'] as const;
export type PaymentProvider = (typeof PAYMENT_PROVIDERS)[number];

/**
 * The provider's own status string, passed through unchanged. It is
 * deliberately a plain `string`: Stripe and iyzico do not use the same
 * vocabulary, the server does not normalise it, and a union invented here would
 * be a third vocabulary that matches neither.
 */
export type SubscriptionStatus = string;

export interface Subscription {
  readonly id: number;
  readonly provider: PaymentProvider;
  readonly plan: string;
  readonly status: SubscriptionStatus;
  readonly current_period_start: string | null;
  readonly current_period_end: string | null;
  readonly canceled_at: string | null;
  readonly created_at: string | null;
}

export interface BillingQuota {
  readonly limit: number;
  readonly used: number;
  readonly remaining: number;
}

export interface BillingSummary {
  /** `null` for a company that has never checked out — the free plan. */
  readonly subscription: Subscription | null;
  /**
   * Read from the **company**, not from the subscription. The plan a company is
   * *on* is raised by the activation listener together with the quota limit, so
   * this stays correct in the window between a webhook landing and the
   * subscription row catching up.
   */
  readonly plan: CompanyPlan;
  readonly quota: BillingQuota;
}

/**
 * The only plan this application sells. `docs/contracts/http-api-v1.md` section 4
 * fixes `plan` to `free | pro`, and `free` is the one plan that is never sold —
 * so the single paid plan is the whole catalogue the SPA needs to know about.
 */
export const UPGRADE_PLAN = 'pro';

export interface CheckoutBody {
  readonly provider: PaymentProvider;
  readonly plan: string;
}

export interface CheckoutSession {
  readonly provider: string;
  readonly checkout_url: string;
  readonly session_id: string;
}

/** Query parameter the provider's return URL is redirected to (`?checkout=`). */
export type CheckoutOutcome = 'success' | 'cancel';

export function isCheckoutOutcome(value: unknown): value is CheckoutOutcome {
  return value === 'success' || value === 'cancel';
}
