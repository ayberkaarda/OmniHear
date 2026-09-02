<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plan quotas
    |--------------------------------------------------------------------------
    |
    | The number of feedback analyses a company may consume. Spec 7.2 fixes the
    | free plan at 200. The `pro` value is decided in the payments phase (F6/F7)
    | and is deliberately null until then so that a wrong number cannot leak
    | into production by accident.
    |
    | Nothing outside this file hard-codes a quota number.
    |
    */

    'plans' => [
        'free' => [
            'quota_limit' => 200,
        ],
        'pro' => [
            'quota_limit' => null,
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
