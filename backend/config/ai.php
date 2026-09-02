<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI service endpoint
    |--------------------------------------------------------------------------
    |
    | The stateless FastAPI analyzer (spec 3.1, invariant I6). It stores nothing;
    | Laravel owns every row that results from a call to it.
    |
    */

    'base_url' => env('AI_SERVICE_URL', 'http://ai-service:8001'),

    /*
    |--------------------------------------------------------------------------
    | Shared HMAC secret
    |--------------------------------------------------------------------------
    |
    | Invariant I7. The signature is HMAC-SHA256 over the *raw request body
    | bytes*, hex encoded, sent as X-Signature. The analyzer recomputes it over
    | the bytes it received (ai-service/app/security.py), so the client must
    | sign exactly the bytes it puts on the wire - re-serializing the payload
    | between signing and sending produces a 401.
    |
    | The dev compose stack sets the same value on both services so they cannot
    | drift apart.
    |
    */

    'hmac_secret' => env('AI_SERVICE_HMAC_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Request timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | The analyzer's SLO is p95 < 800 ms (spec 6.3). The timeout is generous
    | relative to that on purpose: a slow response is retried by the queue, and
    | a timeout that fires inside the SLO would turn a healthy service into a
    | source of dead-lettered jobs.
    |
    */

    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Resilience (spec 3.5)
    |--------------------------------------------------------------------------
    |
    | Exponential backoff, at most `max_attempts` tries, then the job is
    | dead-lettered into `failed_jobs` and the feedback row is marked `failed`.
    | The delays are base_delay * 2^n, capped at `max_attempts - 1` entries
    | because the last attempt is never followed by a wait.
    |
    */

    'retry' => [
        'max_attempts' => (int) env('AI_SERVICE_MAX_ATTEMPTS', 5),
        'base_delay' => (int) env('AI_SERVICE_RETRY_BASE_DELAY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Analysis runs on its own queue so a burst of ingestion cannot starve it,
    | and so Horizon can scale the two workloads independently.
    |
    */

    'queue' => env('AI_ANALYSIS_QUEUE', 'analysis'),

];
