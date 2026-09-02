# Wave 2 — ownership map, seams and endpoint contracts

Status: **binding for F4, F5 and F6/F7.** Written by the main thread before the
wave was dispatched. Three agents work concurrently inside `backend/`, which is a
much tighter space than wave 1's one-directory-per-track split — so this file
settles, in advance, every file more than one of them would otherwise touch.

Companions: `docs/contracts/http-api-v1.md` (wire conventions, error envelope),
`docs/contracts/backend-core.md` (schema, tenancy seam).

---

## 1. Ownership map

Exact and exclusive. If a path is not on your row, you do not write to it.

| track | owns |
|---|---|
| **F4 ingestion** | `app/Support/Connectors/**` · `app/Jobs/FetchFeedbackJob.php` · `app/Http/Controllers/Api/V1/IntegrationController.php` · `app/Http/Requests/Api/V1/Integration/**` · `app/Http/Resources/Api/V1/IntegrationResource.php` · `app/Policies/IntegrationPolicy.php` · `config/connectors.php` · `routes/api/integrations.php` · `routes/console.php` · `tests/Feature/Ingestion/**` · `tests/Unit/Connectors/**` · `contracts/fixtures/platforms/**` |
| **F5 analysis + quota** | `app/Support/Ai/**` · `app/Jobs/{AnalyzeFeedbackJob,RequeuePendingAnalysisJob}.php` · `app/Listeners/**` · `app/Http/Controllers/Api/V1/{FeedbackController,OverviewController}.php` · `app/Http/Resources/Api/V1/{FeedbackResource,AiAnalysisResource}.php` · `app/Policies/{FeedbackPolicy,AiAnalysisPolicy}.php` · `app/Http/Middleware/EnforceQuota.php` · `app/Broadcasting/**` · `config/ai.php` · `routes/api/feedbacks.php` · `routes/channels.php` · **`bootstrap/app.php`** · `tests/Feature/{Analysis,Quota}/**` · `tests/Feature/Contract/**` |
| **F6/F7 payments** | `app/Support/Payments/**` · `app/Http/Controllers/Api/V1/BillingController.php` · `app/Http/Controllers/Api/Webhooks/**` · `app/Policies/SubscriptionPolicy.php` · `config/stripe.php` · `config/iyzico.php` · `routes/api/billing.php` · `routes/api/public/{stripe,iyzico}.php` · `tests/Feature/Payments/**` · `tests/Fixtures/webhooks/{stripe,iyzico}/**` |

**Nobody edits** `routes/api.php`, `app/Support/Http/ApiErrorCode.php`,
`lang/**`, `app/Providers/**`, `app/Models/**`, `database/migrations/**`,
`infra/**`, `docs/**`, `.claude/**`, `CLAUDE.md`, `frontend/**`, `ai-service/**`.
If you need something in one of those, **stop and ask the main thread**.

### Why those files are already settled

- **`routes/api.php`** now requires every file in `routes/api/` inside the
  authenticated group, and every file in `routes/api/public/` outside it. Your
  domain file declares routes directly — it must **not** re-declare the prefix,
  the name prefix or the middleware stack, because it is required inside a group
  that already applies them. `routes/api/public/*.php` gets no group at all, so
  a webhook file declares its own full path.
- **`ApiErrorCode` and `lang/{en,tr}/errors.php`** already contain every wave-2
  code: `INTEGRATION_UNAVAILABLE` (503), `INTEGRATION_INVALID_CREDENTIALS` (422),
  `SYNC_IN_PROGRESS` (409), `AI_SERVICE_UNAVAILABLE` (503),
  `INVALID_WEBHOOK_SIGNATURE` (400), `PAYMENT_PROVIDER_ERROR` (502). Use them.
  Need one that is not there? Ask — it is a contract change, not a local edit.
- **`AuthServiceProvider`** is not to be touched: Laravel auto-discovers
  `App\Policies\{Model}Policy` for `App\Models\{Model}`. Name your policy by that
  convention and it registers itself. Listeners in `app/Listeners` are
  auto-discovered the same way.
- **`config/services.php`** is shared, so payments uses `config/stripe.php` and
  `config/iyzico.php` instead.

---

## 2. The two event seams

Both event classes **already exist in the tree**, written by the main thread, so
that no track has to reference a class another track has not created yet. This is
the whole reason they are events rather than direct calls: a direct call would
mean one agent's test suite cannot run until another agent lands.

### `App\Events\FeedbackIngested` — F4 fires, F5 listens

```php
final class FeedbackIngested
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $feedbackId,
    ) {}
}
```

F4 dispatches it once per **newly created** feedback row (never on an idempotent
re-fetch that hit the `UNIQUE(integration_id, external_id)` constraint — that is
invariant I2, and re-analysing is exactly what it exists to prevent).
F4 must **not** dispatch `AnalyzeFeedbackJob`; it does not own it.
F5 adds a listener that queues analysis.

**F4 tests** assert the event was dispatched (`Event::fake`).
**F5 tests** assert the listener queues the job. Neither needs the other's code.

### `App\Events\SubscriptionActivated` — F6/F7 fires, F5 listens

```php
final class SubscriptionActivated
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $provider, // 'stripe' | 'iyzico'
        public readonly string $plan,     // 'pro'
    ) {}
}
```

Payments dispatches it when a webhook activates a subscription. F5 listens and
re-queues the accumulated `pending_analysis` feedback (spec 7.5). Payments must
**not** touch quota counters, feedback rows or `RequeuePendingAnalysisJob`;
raising the quota limit for the new plan happens in **F5's** listener, from
`config/quota.php`.

---

## 3. Endpoint contracts

Conventions, pagination and the error envelope come from `http-api-v1.md`. These
are the shapes; they are binding because the frontend will be built against them.

### F4 — integrations

| method | path | success |
|---|---|---|
| GET | `/api/v1/integrations` | 200 `{data: [integration], meta}` |
| POST | `/api/v1/integrations` | 201 `{integration}` |
| GET | `/api/v1/integrations/{id}` | 200 `{integration}` |
| PATCH | `/api/v1/integrations/{id}` | 200 `{integration}` |
| DELETE | `/api/v1/integrations/{id}` | 204 |
| POST | `/api/v1/integrations/{id}/sync` | 202 `{message}` |

`POST` body: `{platform, settings: {...}, credentials: {...}}`.
`sync` returns **409 `SYNC_IN_PROGRESS`** when one is already running.

```json
{
  "id": 1, "platform": "appstore", "status": "active",
  "settings": {"app_id": "...", "country": "tr"},
  "last_synced_at": "2026-09-02T11:04:03+00:00",
  "sync_error": null, "feedback_count": 42,
  "created_at": "2026-09-02T11:04:03+00:00"
}
```

**`credentials` is never serialized, in any shape, ever** (invariant I5). Neither
is it echoed back after a write. `sync_error` must be scrubbed of credential
material before it is stored.

### F5 — feedbacks and overview

| method | path | success |
|---|---|---|
| GET | `/api/v1/feedbacks` | 200 `{data: [feedback], meta}` |
| GET | `/api/v1/feedbacks/{id}` | 200 `{feedback}` |
| GET | `/api/v1/overview/kpis` | 200 (below) |

Filters: `sentiment`, `category`, `platform`, `integration_id`, `analysis_status`,
`from`, `to`, `q`, plus `page` / `per_page`.

```json
{
  "id": 1, "integration_id": 1, "platform": "appstore",
  "external_id": "...", "author": "...", "body": "...",
  "source_url": "...", "published_at": "...",
  "analysis_status": "analyzed",
  "analysis": {
    "sentiment_score": -0.5497, "sentiment_label": "negative",
    "category": "bug", "confidence": 0.745,
    "keywords": ["..."], "model_version": "omnihear-onnx-f50df013ccc9",
    "analyzed_at": "..."
  }
}
```

`analysis` is `null` while `analysis_status` is not `analyzed`.
**`raw_payload` is never serialized** — it is bulk provider data and carries PII
the resource has no reason to expose.

`GET /api/v1/overview/kpis`:

```json
{
  "total_feedbacks": 0, "analyzed_count": 0, "pending_analysis_count": 0,
  "average_sentiment": 0.0,
  "sentiment_breakdown": {"positive": 0, "neutral": 0, "negative": 0},
  "category_breakdown": {"complaint": 0, "praise": 0, "bug": 0, "feature_request": 0},
  "trend": [{"date": "2026-09-01", "average_sentiment": 0.12, "count": 3}],
  "quota": {"limit": 200, "used": 12, "remaining": 188}
}
```

### F6/F7 — billing

| method | path | success |
|---|---|---|
| GET | `/api/v1/billing/subscription` | 200 `{subscription: {...}\|null, plan, quota}` |
| POST | `/api/v1/billing/checkout` | 200 `{provider, checkout_url, session_id}` |
| POST | `/api/webhooks/stripe` | 200 |
| POST | `/api/webhooks/iyzico` | 200 |

Checkout body: `{provider: "stripe"|"iyzico", plan: "pro"}`. Only `owner` may call it.

Webhooks are unauthenticated by necessity; **signature verification is what
authenticates them** (spec 7.6), and `webhook_events.event_id` uniqueness is what
makes them replay-safe (invariant I3). A duplicate delivery must return 2xx and
run the business logic **exactly once**. Iyzico carries no native event id, so it
is derived — see `PROGRESS.md` "Verified facts".

---

## 4. Realtime (F5)

`FeedbackAnalyzed` broadcasts on the private channel `private-company.{id}`
(spec 6.5). The channel authorization callback lives in `routes/channels.php` and
must reject a user whose `company_id` does not match the channel id — that is
invariant I1 on the websocket surface, and it needs its own test.

---

## 5. Running tests in parallel

Three agents run `php artisan test` at the same time. A shared database means one
agent's `RefreshDatabase` truncates another's fixtures mid-run and produces a red
that has nothing to do with the code. Per CLAUDE.md section 5, each track gets its own:

| track | database |
|---|---|
| F4 | `test_tmp_f4` |
| F5 | `test_tmp_f5` |
| F6/F7 | `test_tmp_pay` |

Create it once (this is a create, not a drop — no approval needed):

```
docker compose -f infra/docker-compose.dev.yml exec -T postgres createdb -U omnihear test_tmp_<suffix>
```

Then run with it:

```
docker compose -f infra/docker-compose.dev.yml run --rm -e DB_DATABASE=test_tmp_<suffix> backend php artisan test
```

`backend/tests/bootstrap.php` already honours any `test_tmp_`-prefixed name and
forces everything else onto `omnihear_test`, so the dev database cannot be hit by
accident. **Do not drop your database at the end** — the main thread does that at
integration, following the CLAUDE.md section 8 procedure.

Coverage: pcov is now baked into the image, so `--coverage --min=80` works
directly. It did not before; do not re-add a runtime `pecl install`.
