<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API credentials
    |--------------------------------------------------------------------------
    |
    | Sandbox is self-serve (PROGRESS, verified facts 2026-09-02). All three
    | values default to null and every consumer fails closed when one is
    | missing, exactly as in config/stripe.php.
    |
    */

    'api_key' => env('IYZICO_API_KEY'),

    'secret_key' => env('IYZICO_SECRET_KEY'),

    'webhook_secret' => env('IYZICO_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'api_base' => rtrim((string) env('IYZICO_API_BASE', 'https://sandbox-api.iyzipay.com'), '/'),

    'timeout' => (int) env('IYZICO_TIMEOUT', 15),

    'locale' => env('IYZICO_LOCALE', 'tr'),

    /*
    |--------------------------------------------------------------------------
    | Webhook signature
    |--------------------------------------------------------------------------
    |
    | Iyzico signs subscription notifications with `X-IYZ-SIGNATURE-V3` and
    | nothing else — there is no second header and no native event id
    | (PROGRESS, verified facts 2026-09-02).
    |
    | The digest is an HMAC-SHA256 over the *raw* request body keyed with the
    | webhook secret. The transport encoding of that digest could not be
    | confirmed without a live sandbox account, so it is a knob rather than a
    | literal: flipping IYZICO_SIGNATURE_ENCODING to `base64` is the whole
    | change if the merchant panel turns out to emit base64. Both branches are
    | covered by tests, and both compare in constant time.
    |
    */

    'signature_header' => 'X-IYZ-SIGNATURE-V3',

    'signature_encoding' => env('IYZICO_SIGNATURE_ENCODING', 'hex'),

    /*
    |--------------------------------------------------------------------------
    | Delivery attempts
    |--------------------------------------------------------------------------
    |
    | Iyzico gives up after 3 attempts (PROGRESS, verified facts 2026-09-02).
    | Recorded here so the number is not rediscovered; it is the reason a
    | webhook must never answer 5xx for a condition it can decide on, such as
    | an unknown tenant or an event type we do not handle.
    |
    */

    'max_delivery_attempts' => 3,

    /*
    |--------------------------------------------------------------------------
    | Pricing plan reference codes per plan
    |--------------------------------------------------------------------------
    |
    | Keys are plan names from config/quota.php; values are the reference codes
    | issued by the iyzico merchant panel. The webhook maps back through this
    | table, so an unknown reference code resolves to no plan and the event is
    | acknowledged without touching a subscription.
    |
    */

    'pricing_plans' => [
        'pro' => env('IYZICO_PRICING_PLAN_PRO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect target
    |--------------------------------------------------------------------------
    */

    'checkout' => [
        'callback_url' => env('IYZICO_CALLBACK_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:4200'), '/').'/billing/callback'),

        // Iyzico requires a billing address on a subscription customer. We do
        // not collect one — the merchant panel is where the real address is
        // captured — so these are the defaults sent with the initialize call.
        'city' => env('IYZICO_BILLING_CITY', 'Istanbul'),
        'country' => env('IYZICO_BILLING_COUNTRY', 'Turkey'),
    ],

];
