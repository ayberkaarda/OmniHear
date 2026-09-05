<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plan quotas
    |--------------------------------------------------------------------------
    |
    | The number of feedback analyses a company may consume. Spec 7.2 fixes the
    | free plan at 200. The `pro` plan is sold at 5000 — the number a successful
    | payment raises the tenant's limit to (see App\Listeners\ActivateSubscriptionPlan).
    |
    | Nothing outside this file hard-codes a quota number.
    |
    */

    'plans' => [
        'free' => [
            'quota_limit' => 200,
        ],
        'pro' => [
            'quota_limit' => 5000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft warning threshold
    |--------------------------------------------------------------------------
    |
    | Fraction of the quota at which the soft warning fires (spec 7.3).
    |
    */

    'warning_threshold' => 0.8,

];
