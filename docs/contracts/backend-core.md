# Backend core contract — schema + tenancy seam

Status: **binding for F2+**. Written by the main thread before the F2 wave, so
that F4 (ingestion), F5 (quota pipeline) and F6/F7 (payments) can be built
against fixed table and class names without re-litigating the foundation.

Companion: `docs/contracts/http-api-v1.md` (the wire contract).
Source of truth for behaviour: `docs/OMNIHEAR-SPEC.md` sections 5, 6, 7.

---

## 1. Schema

All migrations land in F2 as **one coherent schema**, even for tables whose
behaviour arrives later. Splitting them across phases would mean a later phase
editing an earlier phase's migration, which is exactly the merge conflict the
parallel wave exists to avoid.

PostgreSQL 16. All timestamps are `timestamptz`. All FKs are `bigint` with
`ON DELETE CASCADE` unless noted.

### `companies`

| column | type | notes |
|---|---|---|
| `id` | bigserial PK | |
| `name` | varchar(255) NOT NULL | |
| `plan` | varchar(20) NOT NULL DEFAULT `'free'` | `free` \| `pro` |
| `analyzed_feedback_count` | bigint NOT NULL DEFAULT 0 | incremented atomically (spec 6.4) |
| `quota_limit` | bigint NOT NULL DEFAULT 200 | seeded from `config/quota.php` |
| `created_at` / `updated_at` | timestamptz | |

### `users`

| column | type | notes |
|---|---|---|
| `id` | bigserial PK | |
| `company_id` | FK -> companies | indexed |
| `name` | varchar(255) NOT NULL | |
| `email` | varchar(255) NOT NULL UNIQUE | globally unique, not per tenant |
| `email_verified_at` | timestamptz NULL | |
| `password` | varchar(255) NOT NULL | |
| `role` | varchar(20) NOT NULL DEFAULT `'member'` | `owner` \| `admin` \| `member` |
| `two_factor_secret` | text NULL | `encrypted` cast, `$hidden` |
| `last_login_ip` | varchar(45) NULL | `$hidden` |
| `remember_token` | varchar(100) NULL | |
| timestamps | | |

### `subscriptions`

`id` · `company_id` FK · `provider` varchar(20) (`stripe`\|`iyzico`) ·
`provider_subscription_id` varchar(255) · `plan` varchar(20) ·
`status` varchar(30) · `current_period_start` / `current_period_end` timestamptz NULL ·
`canceled_at` timestamptz NULL · timestamps.

- `UNIQUE (provider, provider_subscription_id)`
- index `(company_id, status)`

### `integrations`

`id` · `company_id` FK · `platform` varchar(30) ·
`credentials` **text** NULL · `settings` jsonb NULL ·
`status` varchar(20) NOT NULL DEFAULT `'active'` ·
`last_synced_at` timestamptz NULL · `sync_cursor` varchar(255) NULL ·
`sync_error` text NULL · timestamps. Index `(company_id, status)`.

- `platform`: `appstore` \| `googleplay` \| `zendesk` \| `trustpilot` \| `email` \| `social` \| `fixture`
  (`fixture` exists for the F4 test connector and never appears in the UI).
- `status`: `active` \| `error` \| `paused`
- **`credentials` is `text`, not `jsonb` — deviation from spec section 5, on purpose.**
  Laravel's `encrypted:array` cast writes a base64 ciphertext string; a `jsonb`
  column would reject it. Encryption is the harder requirement (invariant I5), so
  the column type yields. Cast: `'credentials' => 'encrypted:array'`.
- `settings` (non-secret connector config: app id, locale, marketplace) is an
  **addition** to the spec. Without it every connector would have to stuff
  non-secret config into the encrypted blob, which then cannot be queried or
  shown in the UI.
- `sync_cursor` is an **addition** required by spec 6.1 ("cursor/timestamp based
  incremental fetch, full scans forbidden"). The spec mandates the behaviour but
  lists no column for it.
- `sync_error` must never contain credential material (invariant I5).

### `feedbacks`

`id` · `company_id` FK · `integration_id` FK · `external_id` varchar(255) ·
`author` varchar(255) NULL · `body` text NOT NULL · `source_url` text NULL ·
`published_at` timestamptz NULL · `raw_payload` jsonb NOT NULL ·
`analysis_status` varchar(20) NOT NULL DEFAULT `'pending_analysis'` · timestamps.

- **`UNIQUE (integration_id, external_id)` — invariant I2.**
- `analysis_status`: `pending_analysis` \| `analyzing` \| `analyzed` \| `failed`.
  An **addition**, required by spec 7.4: when quota runs out, feedback
  accumulates in `pending_analysis` and is re-queued after an upgrade. Without a
  status column there is nothing to re-queue.
- index `(company_id, published_at DESC)` for the inbox, `(company_id, analysis_status)`
  for the re-queue sweep.
- Monthly partitioning on `published_at` is planned (spec section 5) but **not**
  implemented in F2; the index above is the interim answer.

### `ai_analyses`

`id` · `feedback_id` FK **UNIQUE** · `company_id` FK · `sentiment_score` numeric(4,3)
CHECK between -1 and 1 · `sentiment_label` varchar(10) · `category` varchar(20) ·
`confidence` numeric(4,3) CHECK between 0 and 1 · `keywords` jsonb NOT NULL ·
`model_version` varchar(50) NOT NULL · `analyzed_at` timestamptz NOT NULL · timestamps.

- `company_id` is an **addition** (the spec lists none). Invariant I1 requires
  every tenant table to carry the discriminator directly; reaching the tenant
  through `feedback_id` would make the global scope a join, and KPI aggregation
  would pay for that join on every dashboard load.
- `sentiment_label`: `positive` \| `neutral` \| `negative`.
- `category`: `complaint` \| `praise` \| `bug` \| `feature_request`.
  These two enums must stay identical to `ai-service/app/schemas.py`.

### `webhook_events`

`id` · `provider` varchar(20) · `event_id` varchar(255) **UNIQUE** ·
`payload` jsonb NOT NULL · `processed_at` timestamptz NULL · timestamps.

- **`UNIQUE (event_id)` — invariant I3.**
- **This is the one table with no `company_id`, on purpose.** A webhook arrives
  before the tenant is known; the tenant is resolved *from* the payload. It must
  not use `BelongsToCompany`, and `tenant-scope-guard` findings on this model are
  expected — annotate with `// tenant-scope: bypass-ok webhook arrives pre-tenant`.
- Iyzico carries no native event id, so it is derived (see PROGRESS, 2026-09-02).

### `audit_logs`

`id` · `company_id` FK · `user_id` FK NULL (`ON DELETE SET NULL`) ·
`action` varchar(100) · `subject_type` varchar(255) NULL · `subject_id` bigint NULL ·
`ip` varchar(45) NULL · `created_at` timestamptz NOT NULL.
No `updated_at` — audit rows are immutable. Index `(company_id, created_at DESC)`.

---

## 2. Tenancy seam (invariant I1)

Fixed class names. Later phases depend on these exact strings.

### `App\Support\Tenancy\TenantContext`

Bound as a singleton in `AppServiceProvider`.

```php
public function id(): ?int;
public function set(?int $companyId): void;
public function has(): bool;
/** Sets the context, runs $callback, restores the previous value in a finally. */
public function runFor(int $companyId, Closure $callback): mixed;
```

### `App\Models\Scopes\CompanyScope implements Scope`

Adds `where {table}.company_id = TenantContext::id()`.

**When the context is unset it throws `App\Exceptions\MissingTenantContextException`.**
Fail closed and loud. A scope that silently returns everything is a cross-tenant
leak; one that silently returns nothing is a bug that looks like empty data.

### `App\Models\Concerns\BelongsToCompany`

- registers `CompanyScope` in `booted()`
- on `creating`, fills `company_id` from `TenantContext` when the attribute is unset
- provides `company(): BelongsTo`

Applied to: `Subscription`, `Integration`, `Feedback`, `AiAnalysis`, `AuditLog`.

### Two documented exemptions

| model | why it is exempt | what enforces the boundary instead |
|---|---|---|
| `User` | Authentication must resolve a user *before* a tenant exists — login finds the user by email, and that is what establishes the tenant. A global scope here deadlocks Sanctum token resolution. | `UserPolicy` + explicit `company_id` filters on team endpoints + a dedicated isolation test |
| `Company` | It *is* the tenant. Scoping a company by its own id is circular. | `CompanyPolicy` + `TenantContext` at the query sites |

These two are the **only** exemptions. Any third one is a contract change.

### `App\Http\Middleware\SetTenantContext`

Runs after `auth:sanctum` on every `/api/v1` route: sets the context from
`$request->user()->company_id`, and clears it after the response. Not applied to
webhook routes.

### Queue jobs

Every tenant-touching job carries the tenant explicitly:

```php
abstract class TenantAwareJob implements ShouldQueue
{
    public function __construct(public readonly int $companyId) {}
    /** Subclasses implement handleForTenant(); the base wraps it in runFor(). */
    abstract protected function handleForTenant(): void;
    final public function handle(TenantContext $tenant): void
    {
        $tenant->runFor($this->companyId, fn () => $this->handleForTenant());
    }
}
```

A queue worker is a long-lived process: a job that forgets to set the context
would otherwise inherit whatever the previous job left behind. `runFor` restores
the previous value in a `finally`, so it cannot leak between jobs.

### Escape hatch

`Model::withoutGlobalScope(CompanyScope::class)` and raw `DB::table()` are
allowed only with a `// tenant-scope: bypass-ok <reason>` comment on the line
above (this is what silences `tenant-scope-guard`). Every bypass needs a reason a
reviewer can check.

---

## 3. Required tests (a phase does not close without these)

| invariant | test |
|---|---|
| I1 | Tenant A authenticated, requests tenant B's row by id -> **404**, not 403. One test per tenant model. |
| I1 | `CompanyScope` with no context throws `MissingTenantContextException`. |
| I1 | `TenantContext::runFor` restores the previous value even when the callback throws. |
| I1 | `User` and `Company`, despite being exempt, do not leak across tenants through the team/company endpoints. |
| I2 | Inserting the same `(integration_id, external_id)` twice violates the unique index. |
| I3 | Inserting the same `event_id` twice violates the unique index. |
| I5 | `Integration::toArray()` contains no `credentials` key; a connector failure writes a `sync_error` with no credential substring. |

---

## 4. Config files this establishes

- `config/quota.php` — `plans.free.quota_limit = 200`, `plans.pro.quota_limit = <payments phase>`,
  `warning_threshold = 0.8` (spec 7.3). Nothing hard-codes these numbers elsewhere.
- `config/tenancy.php` — nothing yet; created when a second knob appears.
- `lang/en/errors.php`, `lang/tr/errors.php` — one entry per error code in the
  HTTP contract catalogue.
