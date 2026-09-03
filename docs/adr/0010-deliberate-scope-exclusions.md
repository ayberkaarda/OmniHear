# ADR-0010 — Deliberate scope exclusions

- **Status:** Accepted
- **Date:** 2026-09-03
- **Phase:** W6
- **Related spec:** §2 (tech stack), §4 (frontend), §6 (data flow), §7 (auth/security), §11 (deploy) as cited per item below
- **Related:** `docs/PROGRESS.md` "Open decisions" / "Known deviations" tables

## Context

CLAUDE.md §6 forbids adding features beyond the spec without approval, but it
equally forbids letting spec items silently disappear — an unbuilt requirement
has to be a recorded decision, not an absence nobody can point to. This ADR is
that record: one entry per spec-mandated item this codebase does not build,
each checked directly against the source tree (not against `docs/PROGRESS.md`'s
memory of it) as of this date, with a one-line reason.

Every claim below was verified by search immediately before writing it:
`grep`/`find` across `backend/app`, `ai-service/app`, `infra/`, and
`.github/workflows/` for the relevant symbol, file, or directory. Where a
partial implementation exists (2FA), that is stated rather than rounded to
"not built."

## Decision

The following are deliberately not built. None is a bug; each is scope this
tree has not entered.

### TOTP 2FA
Spec §7.1 lists `users.2fa_secret` and calls two-factor authentication out as
optional. `backend/app/Models/User.php` has the column (`two_factor_secret`,
`encrypted` cast) and a `twoFactorEnabled(): bool` check that
`UserResource` exposes as `two_factor_enabled`, so the *shape* is in the schema
and the API — but no enrollment endpoint, no TOTP verification step in login,
and no TOTP library (`composer.json` has no `pragmarx/google2fa` or
equivalent) exist. The column can never actually be populated today, so
`two_factor_enabled` is always `false`. **Reason:** spec marks it optional and
no phase has scheduled it.

### IP + device fingerprint risk scoring
Spec §7.1 mentions IP/device signals as a security enhancement.
`users.last_login_ip` is captured (`docs/contracts/backend-core.md` §1) but
nothing reads it back for risk decisions — no `grep` hit for
`risk_score`/`device_fingerprint`/any risk-scoring class anywhere in
`backend/app`. **Reason:** not scheduled; `last_login_ip` exists for audit
value on its own, not as an input to a scoring system that does not exist.

### Sentry (error tracking)
Spec §3.6 asks for Sentry alongside structured logging. No `sentry` reference
anywhere in `backend/composer.json`, `ai-service/pyproject.toml`, or source.
**Reason:** the structured JSON logging half of §3.6 is built (Laravel's
`json` channel, `ai-service/app/logging_config.py` as of this wave) and
carries a correlation id across both services; an external error-tracking SaaS
is a separate integration with its own account/DSN management that no phase
has taken on.

### Prometheus / Grafana (metrics)
Same spec §3.6 line as Sentry. No `prometheus` reference anywhere in the
tree, no `/metrics` endpoint in either backend or ai-service. **Reason:** same
as Sentry — logging is built, metrics-export infrastructure is a separate
undertaken not yet scheduled.

### Google Play, Trustpilot, email, and social connectors
Spec §2 names six channels. `backend/app/Support/Connectors/` contains
`AppStoreConnector`, `ZendeskConnector`, and `FixtureConnector` (the test-only
connector documented in `docs/contracts/backend-core.md` §1 as never
appearing in the UI) implementing `PlatformConnector`. Google Play,
Trustpilot, email, and social have no connector class, no entry in
`ConnectorFactory`, and — per `docs/PROGRESS.md`'s "Open after W4" — even
Zendesk's shape was synthesised from documentation rather than verified
against a live account. **Reason:** each connector is its own ingestion,
auth, and fixture-synthesis effort (see `platform-connector` skill); F4/F8
built two real feeds plus the test fixture, and the remaining four were never
dispatched.

### The backend's use of `/analyze/batch`
Spec §6.3 offers a batch endpoint for bulk work. `ai-service` implements it
(`ai-service/app/routers/analyze.py`, `POST /v1/analyze/batch`, tested in
`ai-service/tests/test_analyze.py`) but `backend/app/Support/Ai/AiClient.php`
declares only `analyze()` — no `analyzeBatch()` method, no call site anywhere
in `backend/app` reaches the batch route. **Reason:** `AnalyzeFeedbackJob`
processes one feedback row per job by queue design (see its own docstring:
quota is reserved per-row so a partial failure costs exactly one unit), and
nothing in the ingestion pipeline currently groups rows before dispatch — so
there has been no caller shaped to use a batch call.

### A reprocess command for `model_version`
Spec §5's note that `ai_analyses.model_version` is kept so "old analyses can
be reprocessed when the model updates" implies a sweep command. No Artisan
command matching `*Reprocess*` exists in `backend/app/Console`, and no code
path issues `WHERE model_version <> :current`. **Reason:** `model_version`
itself is built and is a genuine deterministic hash (ADR-0004,
`ai-service/app/model_version.py`) — the data needed to write this command
exists — but the command that consumes it was never scheduled.

### Staging deploy and production promotion
Spec §11 (deploy) implies an environment-promotion pipeline. `.github/workflows/`
contains only `ci.yml` (test/build/lint on push); no `staging`, `deploy`, or
`promote` workflow, no `grep` hit for those words in any workflow file.
**Reason:** `docs/PROGRESS.md` D-05 records the repository stays private
until the project is finished, and this is a portfolio project with no
target infrastructure to deploy to — there is no environment to promote into
yet.

### K8s manifests
Spec §2 calls the container images "K8s-ready" as a forward-looking property,
not a requirement to ship manifests. `infra/` contains only
`docker-compose.dev.yml` and its supporting `docker/` Dockerfiles — no `k8s/`
directory, no `*.yaml` deployment/service/ingress manifest anywhere in the
repository. **Reason:** ADR-0002 already commits to container-authoritative
runtime for dev; a K8s target has no cluster to deploy to (same absence as
the previous item) and would be speculative infrastructure with nothing to
validate it against.

### Redis KPI cache
Spec §2 lists Redis's purpose as including "KPI agregasyonları için."
`backend/app/Http/Controllers/Api/V1/OverviewController.php` computes every
KPI (status counts, sentiment/category breakdowns, the 30-day trend) as live
grouped Eloquent queries on every request — no `Cache::remember` or similar
call anywhere in the controller or its supporting classes. Redis is in active
use elsewhere (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` generically,
Horizon) but nothing routes the overview endpoint through it specifically.
**Reason:** `OverviewController`'s own docstring explains the current
approach was chosen to avoid an N+1 fetch-and-count in PHP by pushing
aggregation into the database — that got the correctness property (accurate,
per-tenant, zero-filled breakdowns) right first. A cache layer on top adds an
invalidation problem (every `AnalyzeFeedbackJob` write would need to bust it)
that has not been designed, and no phase has measured the KPI endpoint as a
bottleneck that would justify taking it on.

## Alternatives considered

This ADR does not itself choose between competing designs — each entry above
already states why the corresponding feature was not attempted. The
alternative to writing this ADR at all was to leave these absences implicit
in `docs/PROGRESS.md`'s prose tables. Rejected: PROGRESS has a 120-line cap
and closed phases collapse to one row, so it cannot durably hold nine
individually-justified exclusions — precisely the failure mode CLAUDE.md §6
warns about, where an unbuilt requirement is indistinguishable from an
oversight.

## Consequences

**Positive.** Anyone auditing this repository against the spec — including a
future session of this same project — has one file that both lists every
known gap and shows it was checked against the code, not assumed. Re-running
the same `grep`/`find` commands this ADR used re-verifies it.

**Negative / accepted debt.** This ADR is a snapshot. Nine independent absences
drift at nine independent rates — Zendesk's connector could be joined by a
tenth exclusion tomorrow, or 2FA could land partially and make its own entry
stale, and nothing automatically re-checks this file against the tree. It is
correct as of 2026-09-03 and needs a manual re-audit at a later phase gate if
relied on much further out.

**Not covered.** This ADR does not prioritize which exclusion should be
built next — that is a scope decision for the user, not an architectural one.

## Related spec section

`docs/OMNIHEAR-SPEC.md` §2, §3.6, §4, §5, §6.3, §7.1, §11.
