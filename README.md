# OmniHear

A B2B SaaS platform that collects customer feedback from multiple channels (App
Store, Zendesk today; the full spec list and what's not built yet is in
`docs/adr/0010-deliberate-scope-exclusions.md`), runs it through an AI pipeline
for sentiment and category classification, and gives companies a real-time
inbox with quota-gated analysis and a paywall.

## Architecture, in one paragraph

Three services. `backend/` (Laravel 13, PHP 8.3, PostgreSQL 16) owns all
product data and multi-tenancy — every table scoped to a `company_id`, cross-
tenant access answers 404, never 403. Feedback ingestion and AI analysis run
as Horizon/Redis queue jobs, never inside an HTTP request. `ai-service/`
(Python 3.12, FastAPI, ONNX) is a **stateless** analyzer: it takes text over
an HMAC-signed internal call and returns sentiment + category, keeping
nothing. `frontend/` (Angular 22, standalone + Signals, zoneless) is the SPA —
inbox, KPI overview, integrations, billing, all lazy-loaded behind a ~335 kB
initial bundle budget. Full diagram and data flow: **`docs/ARCHITECTURE.md`**.

## Setup

Requirements: Docker + Docker Compose. Everything below runs inside
containers — `docs/LESSONS.md` (2026-09-02) records that the host toolchain is
not authoritative: PHP 8.3 and PostgreSQL 16 are pinned in the images, and a
host running an older PHP cannot run `artisan` directly at all.

```bash
git clone <this repository>
cd SaaaS

cp backend/.env.example backend/.env
docker compose -f infra/docker-compose.dev.yml up -d
docker compose -f infra/docker-compose.dev.yml exec backend php artisan key:generate
docker compose -f infra/docker-compose.dev.yml exec backend php artisan migrate --force
docker compose -f infra/docker-compose.dev.yml exec backend php artisan db:seed --class=DemoCompanySeeder
```

`docker compose up -d` builds and starts eight services: `postgres`, `redis`,
`ai-service`, `mailpit`, `backend`, `horizon` (queue worker), `reverb`
(realtime), and `frontend`. The frontend service installs its dependencies
inside the container rather than borrowing the host's `node_modules`, so the
first start is slower than the rest.

**What was actually executed and verified this session**, on a checkout whose
`backend/.env` a prior phase had already populated (so `cp` and
`key:generate` were not re-run here — overwriting the live `.env`/`APP_KEY`
would have invalidated every currently-issued Sanctum token in this shared
dev environment, which is a needless disruption on top of the two commands
being ordinary, well-known Laravel steps with no surprising behaviour):
`docker compose up -d` (all eight services except `frontend`, see "Known
gap"), `artisan migrate --force`, `artisan db:seed --class=DemoCompanySeeder`,
then the login and `/health` calls quoted below, all against the running
containers with real output. On a genuinely fresh clone, run every command in
this section in order, top to bottom.

Seeding, run against this checkout:

```
$ docker compose -f infra/docker-compose.dev.yml exec backend php artisan db:seed --class=DemoCompanySeeder
   INFO  Seeding database.
  Database\Seeders\DemoCompanySeeder ................................. RUNNING
DemoCompanySeeder: company 147, sign in as owner@omnihear.demo / demo-password-2026. Quota 60/75.
  Database\Seeders\DemoCompanySeeder ........................... 1,338 ms DONE
```

`DemoCompanySeeder` (`backend/database/seeders/DemoCompanySeeder.php`) exists
specifically so a reviewer sees a working product within a minute of cloning,
rather than an empty dashboard: one company on the free plan with a
**deliberately low quota** (75, not the real 200), an owner/admin/member user
for every role, one credential-free `fixture` integration, and 60 analyzed
feedback rows spread across every sentiment label and category over the last
month, so the trend charts and KPI breakdowns have real shape. It refuses to
run twice for the same company (checked by name), so re-running it is safe.

## Log in

Credentials the seeder prints: `owner@omnihear.demo` /
`demo-password-2026` (also `admin@omnihear.demo`, `member@omnihear.demo`,
same password). Verified against this checkout:

```
$ curl -s -X POST http://localhost:8000/api/v1/auth/login \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -d '{"email":"owner@omnihear.demo","password":"demo-password-2026"}'
{"token":"14|...","user":{"id":90,"company_id":147,"name":"Demo Owner","email":"owner@omnihear.demo","role":"owner", ...},
 "company":{"id":147,"name":"OmniHear Demo","plan":"free","analyzed_feedback_count":60,"quota_limit":75,"quota_remaining":15, ...}}
```

Every authenticated response also carries an `X-Quota-Remaining` header —
confirmed `15` on `GET /api/v1/overview/kpis` with the token above, matching
`quota_remaining` in the login response.

## What you'll see

- **The dashboard is not empty.** 60 analyzed feedback rows across every
  sentiment label (`positive`/`neutral`/`negative`) and category
  (`bug`/`complaint`/`praise`/`feature_request`), dated over the last month,
  so KPI breakdowns and the trend chart have real shape rather than zeroes.
- **The paywall is reachable immediately, not after 200 real analyses.** The
  demo company's quota is 75, already 60 used (80%) — the soft warning
  threshold has already fired, and 15 more successful analyses trip
  `402 QUOTA_EXCEEDED`. A real company's default quota is 200
  (`config/quota.php`); the seeder deliberately does not use that number,
  because demonstrating the paywall at 200 would mean ingesting 200 real rows
  first.
- **`GET /health` on the AI service** reports the active backend, confirmed
  on this checkout:
  ```
  $ curl -s http://localhost:8001/health
  {"status":"ok","service":"ai-service","model_version":"omnihear-onnx-f50df013ccc9","sentiment_backend":"onnx"}
  ```
  `sentiment_backend: "onnx"` means the real ~171 MB multilingual sentiment
  model is loaded, not the weaker lexicon fallback — see
  `ai-service/README.md` and `ai-service/MODEL_CARD.md` for what the
  difference means for analysis quality.

## Repository layout

```
backend/       Laravel 13 · PHP 8.3 · Sanctum · Horizon+Redis · Reverb · Pest · PostgreSQL 16
frontend/      Angular 22 standalone + Signals · TailwindCSS · @angular/localize (TR/EN)
ai-service/    Python 3.12 · FastAPI · Pydantic v2 · pytest · Ruff — see ai-service/README.md
contracts/     OpenAPI schema + fixtures shared by backend and ai-service tests
infra/         docker-compose.dev.yml, Dockerfiles
docs/          ADRs (docs/adr/), ARCHITECTURE.md, PROGRESS.md, LESSONS.md
.claude/       hooks/ · skills/ · settings.json
```

Per-directory setup: `ai-service/README.md`, `frontend/README.md`. The
backend has no separate README pass in this documentation wave — its
Laravel-generated one is unchanged.

## Operations notes

**Access tokens expire.** Sessions last 14 days, API keys 90, with a 90-day
ceiling in `config/sanctum.php`. The limit is measured from when a token was
created, so the first deploy that carries this setting invalidates every token
older than 90 days: users sign in again, and any API key that old has to be
re-minted. That is deliberate — before it, a leaked bearer token was valid
forever — but it is worth saying out loud in a release note.

**An API key is not a session.** A key reaches only the tenant's own data and
the ingestion trigger; account deletion, device sessions, key minting, profile,
team, billing and the private broadcast channel are session-only. The full list
is in `docs/contracts/settings-api.md` §3.

## Documentation map

- **`docs/ARCHITECTURE.md`** — the three services, the data flow diagrams
  (ingestion → analysis → broadcast, the Laravel↔FastAPI contract, payments,
  the tenant boundary), mermaid, renders on GitHub.
- **`docs/adr/`** — architecture decision records, numbered. Notably
  `0009-feedbacks-partitioning-deferred.md` (why `feedbacks` isn't
  partitioned yet and what would trigger doing it) and
  `0010-deliberate-scope-exclusions.md` (every spec item deliberately not
  built, checked against the code, with a one-line reason each).
- **`docs/PROGRESS.md`** — phase-by-phase status; **`docs/LESSONS.md`** —
  append-only log of things that cost a debugging session to find.
- **`docs/contracts/`** — the binding shape of the HTTP API, the backend's
  schema and tenancy seam, realtime events, and the settings API.
