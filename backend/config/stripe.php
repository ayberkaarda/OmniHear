<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API credentials
    |--------------------------------------------------------------------------
    |
    | Never committed. `config/services.php` is shared by every track, so the
    | payment providers get their own files (docs/contracts/wave2-seams.md).
    |
    | Both values default to null. A missing key is treated as a hard failure at
    | the point of use — a checkout raises PAYMENT_PROVIDER_ERROR and a webhook
    | with no configured signing key fails signature verification. Failing
    | closed matters more here than a helpful boot-time error, because the
    | webhook route is the one unauthenticated surface in the application.
    |
    */

    'secret' => env('STRIPE_SECRET'),

    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | The base URI is configurable so tests can pin it and Http::fake() can
    | match on it without depending on a hard-coded literal in two places.
    |
    */

    'api_base' => rtrim((string) env('STRIPE_API_BASE', 'https://api.stripe.com'), '/'),

    'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),

    'timeout' => (int) env('STRIPE_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Signature tolerance
    |--------------------------------------------------------------------------
    |
    | Maximum age, in seconds, of the timestamp carried in the Stripe-Signature
    | header. Stripe's own default is 300. Without it a captured request could
    | be replayed forever by anyone who observed it; the event_id unique index
    | (invariant I3) stops the business logic from running twice, but the
    | tolerance is what stops the request from being accepted at all.
    |
    */

    'signature_tolerance' => (int) env('STRIPE_SIGNATURE_TOLERANCE', 300),

    /*
    |--------------------------------------------------------------------------
    | Price ids per plan
    |--------------------------------------------------------------------------
    |
    | Keys are plan names from config/quota.php. Quota *numbers* live there and
    | nowhere else; this map only says which Stripe price a plan is sold at.
    |
    */

    'prices' => [
        'pro' => env('STRIPE_PRICE_PRO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect targets
    |--------------------------------------------------------------------------
    |
    | Stripe sends the customer back to the SPA, never to this API.
    |
    */

    'checkout' => [
        'success_url' => env('STRIPE_SUCCESS_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:4200'), '/').'/billing/success'),
        'cancel_url' => env('STRIPE_CANCEL_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:4200'), '/').'/billing/cancel'),
    ],

];
