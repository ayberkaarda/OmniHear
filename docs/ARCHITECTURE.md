# Architecture

What is actually running in this repository today, not an idealised target. Where
the spec asks for something not yet built, it is left out of the diagram below and
tracked instead in `docs/adr/0010-deliberate-scope-exclusions.md`.

Source of truth for the numbered claims here: `docs/contracts/backend-core.md`,
`docs/contracts/http-api-v1.md`, `docs/contracts/realtime.md`, and the three
services' own source trees.

---

## 1. The three services

| Service | Stack | Owns | Statefulness |
|---|---|---|---|
| `backend/` | Laravel 13, PHP 8.3, Sanctum, Horizon+Redis, Reverb, PostgreSQL 16 | All product data, tenancy, quota, payments, ingestion scheduling, realtime broadcast | Stateful — the only service with a database |
| `ai-service/` | Python 3.12, FastAPI, ONNX Runtime | Language detection, sentiment, category, keyword extraction | **Stateless** (ADR-0004) — text in, JSON out, nothing persisted |
| `frontend/` | Angular 22, standalone components + Signals, TailwindCSS, zoneless | The SPA: landing, auth, inbox, integrations, settings, billing, `/402` | Stateless — Signal Store holds client-side view state only |

The backend is the only service that talks to a database or knows what a
"company" is. The AI service never sees a tenant, a feedback id, or a
correlation id's history — it is called once per request and forgets
everything it returned.

## 2. Data flow: ingestion through analysis to broadcast

```mermaid
sequenceDiagram
    autonumber
    participant Sched as Laravel Scheduler
    participant Fetch as FetchFeedbackJob
    participant Conn as PlatformConnector<br/>(AppStore / GooglePlay / Zendesk / Trustpilot / Email / Social)
    participant DB as PostgreSQL<br/>(feedbacks)
    participant Analyze as AnalyzeFeedbackJob
    participant Quota as QuotaCounter<br/>(atomic)
    participant AI as ai-service<br/>/v1/analyze
    participant Bus as Reverb<br/>(private-company.{id})
    participant SPA as Angular SPA

    Sched->>Fetch: dispatch every 5 min, per active integration
    Fetch->>Conn: fetch(cursor)
    Conn-->>Fetch: page of items (cursor/timestamp based, no full scans)
    Fetch->>DB: upsert feedbacks<br/>UNIQUE(integration_id, external_id) — I2
    DB-->>Analyze: FeedbackIngested event dispatches AnalyzeFeedbackJob
    Analyze->>Quota: reserve one unit (atomic increment)
    alt quota available
        Quota-->>Analyze: reserved
        Analyze->>AI: POST /v1/analyze<br/>HMAC-signed body + X-Correlation-Id
        AI-->>Analyze: {sentiment, category, confidence, keywords, model_version}
        Analyze->>DB: write ai_analyses, feedbacks.analysis_status = analyzed
        Analyze->>Bus: broadcast feedback.analyzed
        Bus-->>SPA: private-company.{id} — inbox row updates in place
    else quota exhausted
        Quota-->>Analyze: 402-equivalent, no unit spent
        Analyze->>DB: feedbacks.analysis_status stays pending_analysis
        Note over DB: row accumulates, never dropped (I4).<br/>RequeuePendingAnalysisJob re-queues it after upgrade.
    end
```

Two things worth stating plainly:

- **The AI call never happens inside an HTTP request/response cycle.** Both
  ingestion and analysis are queue jobs (Horizon workers over Redis); a slow or
  down analyzer degrades queue depth, never a page load.
- **Quota is reserved before the analyzer is called, not after.** An exhausted
  quota therefore costs zero inference calls — the point of spec §7.4. If the
  call then fails, the reservation is released, so retries don't multiply the
  cost of one failing analysis.

## 3. Request/response shape: Laravel <-> FastAPI

```mermaid
flowchart LR
    L["Laravel<br/>AiClient::analyze()"] -- "raw body bytes\n+ X-Signature (HMAC-SHA256)\n+ X-Correlation-Id" --> A["FastAPI\nverify_signature()"]
    A -- "401 INVALID_SIGNATURE\nif mismatch" --> L
    A -- "signature OK" --> P["Analyzer pipeline\nlanguage → sentiment → category → keywords"]
    P --> A2["AnalyzeResponse\n{sentiment_score, sentiment_label,\ncategory, confidence, keywords,\nmodel_version, correlation_id}"]
    A2 -- "JSON" --> L
```

The signature covers the exact bytes on the wire — the body is encoded once,
signed, and never re-encoded by the HTTP client, because a different key order
would produce a different signature on an equivalent payload. `correlation_id` is
caller-supplied by Laravel (propagated from the inbound HTTP request via
`CorrelationId` middleware) and both sides log it as a JSON field — Laravel
through `config/logging.php`'s `json` channel, the AI service through
`app/logging_config.py` — so one id ties a request's Laravel line, queue job, and
FastAPI line together in a log search (spec §3.6). Contract shape is enforced by
`contracts/` fixtures both sides read (invariant I7).

## 4. Payments and webhooks

```mermaid
flowchart LR
    S["Stripe / Iyzico"] -- "signed webhook" --> W["StripeWebhookController /\nIyzicoWebhookController"]
    W -- "verify signature" --> E{"event_id already\nin webhook_events?"}
    E -- "yes: duplicate" --> Drop["204, no-op (I3)"]
    E -- "no" --> Rec["insert webhook_events\nUNIQUE(event_id)"]
    Rec --> Act["activate/update subscriptions\n(company_id resolved from payload)"]
```

`webhook_events` is the one table with no `company_id` — a webhook arrives before
the tenant is known, and the tenant is resolved *from* the payload rather than the
other way round (`docs/contracts/backend-core.md` §1). Iyzico carries no native
event id, so one is derived; both providers are covered by
`backend/tests/Fixtures/webhooks/{stripe,iyzico}/`.

## 5. Where the tenant boundary sits

```mermaid
flowchart TB
    subgraph Req["Every /api/v1 request"]
        Auth["auth:sanctum"] --> STC["SetTenantContext middleware\nreads request.user().company_id"]
    end
    STC --> TC["TenantContext (request-scoped singleton)"]
    TC --> CS["CompanyScope\n(global scope on every tenant model)"]
    CS -->|"context set"| Q["WHERE company_id = TenantContext::id()"]
    CS -->|"context unset"| Ex["throws MissingTenantContextException\n(fail closed, not open)"]

    subgraph Jobs["Queue jobs (no HTTP request to hang the context on)"]
        TAJ["TenantAwareJob::handle()"] --> RF["TenantContext::runFor(companyId, ...)\nrestores previous value in a finally"]
        RF --> CS
    end
```

`CompanyScope` is applied via the `BelongsToCompany` trait to `Subscription`,
`Integration`, `Feedback`, `AiAnalysis`, and `AuditLog`. `User` and `Company` are
the two documented exemptions (login must resolve a user *before* a tenant
exists, and a company cannot scope itself) — each is covered by its own
policy and isolation test instead, per `docs/contracts/backend-core.md` §2.
Cross-tenant access to a real row answers **404**, never 403, so existence is
never leaked (invariant I1).

`webhook_events` sits outside this boundary entirely: it carries no
`company_id` and never uses `BelongsToCompany` — the one model where a
`tenant-scope-guard` finding is expected and silenced with a documented
`bypass-ok` comment.

## 6. Frontend shape (brief — see `frontend/README.md` for the gate)

The SPA is a single Angular application, every feature route lazy-loaded, state
held in Signal Stores rather than NgRx. `HttpInterceptor`s attach the bearer
token, redirect on `401`, and open the paywall modal on `402 QUOTA_EXCEEDED`.
Realtime (`feedback.analyzed`, `quota.threshold-reached` over Reverb, per
`docs/contracts/realtime.md`) is loaded through a dynamic `import()` after auth
resolves — it does not enter the initial bundle, because `pusher-js` +
`laravel-echo` alone (≈18 kB brotli) would exceed the transfer headroom the
initial-bundle budget leaves (ADR-0007, ADR-0008).

## 7. What is deliberately not in this diagram

TOTP 2FA, IP/device risk scoring, Sentry/Prometheus, `/analyze/batch` usage
from the backend, a `model_version` reprocess command, staging/production
deploy, K8s manifests, and a Redis KPI cache are spec items not built. All six
spec §2 channels now have a connector — that gap is closed. Each remaining
exclusion is recorded with its reason in
`docs/adr/0010-deliberate-scope-exclusions.md` rather than drawn here as if it
existed.
