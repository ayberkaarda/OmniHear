<?php

return [

    /*
    |--------------------------------------------------------------------------
    | KPI cache
    |--------------------------------------------------------------------------
    |
    | Spec 2 lists KPI aggregation among Redis's jobs. GET /api/v1/overview/kpis
    | is six grouped queries over feedbacks and ai_analyses, and the dashboard
    | is the first screen every session opens, so the same six queries run for
    | every tab of every member of a company.
    |
    | The entry is not a materialised view: it is a short-lived copy of the
    | rendered response, invalidated by App\Listeners\InvalidateKpiCache the
    | moment a feedback is ingested or analysed. The TTL is therefore a
    | backstop against a missed invalidation, not the freshness contract —
    | which is why it is measured in a minute rather than an hour.
    |
    | `ttl` of 0 (or less) disables caching entirely and every request computes.
    | That is a real operational escape hatch, not dead configuration: if the
    | cache is ever suspected of serving one tenant's numbers to another, the
    | fix is to turn it off in seconds and keep the product correct while the
    | bug is found.
    |
    */

    'cache' => [
        'ttl' => (int) env('OVERVIEW_KPI_CACHE_TTL', 60),
    ],

];
