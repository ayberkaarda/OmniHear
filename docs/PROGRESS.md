# PROGRESS

Updated: 2026-09-02 · Current: **wave 2 integrated and green; awaiting approval** · Spec: `omnihear-engineering-prompt (1).md`

> Hard cap: 120 lines. A closed phase collapses to one row. Phase report bodies do
> not live here — their numbers land in the table.

## Phases

| # | Scope | Status | Gate evidence (numbers only) |
|---|---|---|---|
| F1 | monorepo skeleton + design-system layer | **closed, committed** (`24ac570`, `f45cece`) | guards 110/110 · tokens 63/2/0 · i18n PASSED · typecheck 0 · eslint 0 · jest 49/49 · build 240.53 kB exit 0 · compose 0 · audit clean · pint 30 files · pest 4/11 · ruff 0 · pytest 13/13 |
| F2 | tenant core (full schema, global scope, policies, Sanctum auth) | **green, re-verified by main thread** | guards 116/116 · pint 107 files · composer validate/audit/platform-reqs 0 · pest **206 passed, 676 assertions**, 49.0 s · coverage **99.2%** |
| F2-FE | app shell, auth flow, landing, interceptors, paywall modal | **green** (budget re-derived, ADR-0007) | tokens 63/2/0 · i18n PASSED 220/220, 0 empty · typecheck 0 · eslint 0 · jest **135/135** · build:gate PASS — raw **301.36 kB**/320, transfer **87.63 kB**/100 |
| F3 | ai-service real analyzer (local pipeline) + contract artifacts | **green, re-verified by main thread** | ruff 0 · format 43 files · pytest **270 passed** 5.4 s · category held-out **83.8%** (TR 85.0 / EN 82.5) · sentiment ONNX **91.7%** · p95 **15.1 ms** · drift gates OK · live signed call 401/401/200 |
| F4 | ingestion (FixtureConnector + AppStoreConnector) | **green** (agent cut off by rate limit; main thread finished) | see the combined backend gate below |
| F5 | analysis pipeline + atomic quota + 402 | **green** (agent cut off by rate limit; main thread finished) | I4 race: 5 forked processes, limit 3 -> exactly 3 · contract test consumes `contracts/fixtures/analyze/` |
| F6 | payments — Stripe | **green** (agent cut off by rate limit; main thread finished) | replay -> side effect exactly once |
| F7 | payments — Iyzico | **green** (agent cut off by rate limit; main thread finished) | derived `event_id`; retries stop at 3 |
| F8 | ingestion — Zendesk | not started | — |

Note: the design-system token layer and the six UI components belong to F7 in the
original plan. They landed in F1 at the user's request, after a design authored in
Claude Design was imported. The phase order is otherwise unchanged.

## Now

**Backend gate, whole tree, re-run by the main thread:** guards **116/116** · pint
PASS 215 files · composer validate/audit/platform-reqs clean · pest **602 passed,
2036 assertions** · coverage **97.8%** · compose config 0.
**ai-service:** ruff 0 · format 43 files · pytest **270 passed**.
**frontend:** tokens 63/2/0 · i18n PASSED · typecheck 0 · eslint 0 · jest 135/135 ·
build:gate PASS (raw 301.36/320 kB, transfer 87.63/100 kB).
Working tree: **151 changed/new paths**, uncommitted.

All three wave-2 agents were killed mid-task by a session rate limit. Their work
was left partially integrated (16 failing tests); the main thread diagnosed and
finished it. Six of those failures were real defects, not loose ends — they are
recorded under "Verified facts" because each one is a trap that will recur.

Wave 1 ran three tracks in parallel. Ownership is disjoint by top-level directory,
so they share one working tree rather than three git worktrees — three worktrees
would each need their own `vendor/`, `node_modules/` and venv for no isolation the
directory split does not already give.

| track | owns | agent |
|---|---|---|
| F2 backend | `backend/` | opus |
| F3 ai-service | `ai-service/`, `contracts/` | opus |
| F2-FE frontend | `frontend/` | opus |

`docs/`, `.claude/`, `.github/`, `infra/`, `CLAUDE.md` stay with the main thread.

**Wave 2 (after F2 lands)** fans out wider: F4 ingestion, F5 quota pipeline, F6
Stripe and F7 Iyzico are independent of each other but all need F2's schema and
tenancy seam, so they cannot start earlier without writing code against classes
that do not exist yet.

Contracts written before dispatch, so no track has to guess at another's shape:
`docs/contracts/http-api-v1.md` (Laravel <-> Angular) and
`docs/contracts/backend-core.md` (schema + tenancy seam).

## Open decisions

| id | question | default | decide by |
|---|---|---|---|
| D-01 | `.claude/` + `CLAUDE.md` public in repo? | **decided: keep.** Narrow reading of the no-attribution rule, confirmed by behaviour: the note was written 01:56:27 and `14a51d8` at 01:58:14 deliberately kept them tracked | closed |
| D-03 | Move root spec file to `docs/OMNIHEAR-SPEC.md` | move | before next large commit |
| D-04 | Spec §2 erratum: "Laravel 11 (PHP 8.3)" → "Laravel 13 (PHP 8.3)" | apply | F2 start |
| D-05 | Repo visibility | **decided 2026-09-02: stays private; goes public when the project is finished.** CI stays dark until then; the three CI-only checks moved into the local gate | closed |
| D-07 | Angular initial-bundle budget | **decided: two thresholds re-derived from the measured floor (ADR-0007).** raw 320kb in `angular.json`, brotli transfer 100 kB in `scripts/bundle-check.mjs`; Trap 2 rewritten with a ratchet | closed |
| D-08 | **Angular 18 is out of support** (angular.dev: v2-v19 no longer supported). Upgrade is required regardless of the budget | target v22 (Active); also turns `provideExperimentalZonelessChangeDetection` into the stable `provideZonelessChangeDetection` and closes that deviation. Own phase, own gate, after wave 2 | schedule after wave 2 |
| D-06 | Real App Store review text is already in history (`24ac570`). Rewriting the fixtures does not remove it; going public later publishes the history | rewrite fixtures now, history rewrite before the flip (cheapest today at 4 commits) | before going public |

## Known deviations from spec

| what | why | reconcile in |
|---|---|---|
| Laravel 13, not 11 | 11 security-EOL 2026-03-12; two unpatched advisories on §7.1 code paths | resolved — ADR-0003, user approved |
| `provideExperimentalZonelessChangeDetection` is experimental in Angular 18 | stabilised unchanged in 19/20; one-line migration | whenever Angular is upgraded |
| `config/session.php`, `config/cache.php` lack Laravel 13 hardening defaults | `composer update` does not regenerate skeleton config | **F2, before auth work** |
| Fonts load from Google Fonts CDN | self-hosting deferred | F9 (KVKK) |
| initial bundle raw budget 250 -> 320kb | measured framework floor is 245.00 kB = 95.7% of the old threshold; spec §4 says "hedefi" and the page tree it mandates cannot be built under it | resolved — ADR-0007; transfer 87.63 kB is well under the spec figure |

## Verified facts (append-only, dated — do not rediscover)

- **2026-09-02** `tsc -p tsconfig.app.json --noEmit` **does not check unrouted files.** The CLI generates `files: ["src/main.ts"]`, so anything unreachable from the entry point is skipped and the command exits 0. Proven: a deliberate `const x: number = "string"` in `shared/ui/` went unreported. Gate now uses `npm run typecheck` (`tsconfig.typecheck.json`). The build still uses `tsconfig.app.json`, so bundle output is unaffected.
- **2026-09-02** Same blind spot hit i18n: 23 marked strings existed with 4 extracted units, and nothing caught it — `ng extract-i18n` cannot see unrouted components either. `i18n:check` rule 5 now compares the source `@@id` count against the trans-unit count.
- **2026-09-02** App Store RSS review feed is live, needs no credentials. Depth limit **10** — `page=11` returns **HTTP 400** with a gzip'd body `CustomerReviews RSS page depth is limited to 10` (not corrupt JSON; read the status code). Pages come back **empty intermittently** — `page=1` returned 0 entries once, then 50 on five retries — so "empty page = end of stream" silently loses data. Fixtures in `contracts/fixtures/platforms/appstore/`.
- **2026-09-02** Iyzico: sandbox is self-serve, signature is `X-IYZ-SIGNATURE-V3` only, subscription webhooks carry **no native event id** (derive `webhook_events.event_id`), retries stop after **3 attempts**.
- **2026-09-02** Host differs from spec: PHP 8.2.12 (no `pcntl`/`posix` on Windows, so Horizon cannot run there), Python 3.14.7, no local `psql`. Containers are authoritative (ADR-0002).
- **2026-09-02** Removing zone.js: 261.52 kB → 226.52 kB initial (polyfills 36.36 → 1.84 kB). `--localize=false` was worth 0.65 kB, `withFetch()` nothing.
- **2026-09-02** An **uncalibrated** colour-blindness simulation produced wrong numbers and nearly drove the opposite palette decision. `tokens-check` now runs calibration assertions first and aborts everything if they fail. Do not remove them.
- **2026-09-02** Three defects survived a fully green gate because **nothing in it connects two services**. An empty named volume over `backend/vendor` shadowed the host directory and killed every `artisan` command; `docker compose config -q` passed anyway (valid syntax, broken behaviour). The HMAC secret differed between backend (`REPLACE_ME` from `.env`) and ai-service (compose default), so the first real signed request returned **401 INVALID_SIGNATURE** — both sides were green in isolation and the contract test uses fixtures, not a live call. Verified by hand afterwards: unsigned → 401, mismatched → 401, matched → 200 with the correct response shape. **A live smoke test belongs in the gate** (proposed, not yet added — needs approval).
- **2026-09-02** **The live two-service smoke test that F1 said was missing now exists and passes.** Against the rebuilt image: `GET /health` returns `{"model_version":"omnihear-onnx-f50df013ccc9","sentiment_backend":"onnx"}` — the ONNX backend really is what runs in the container, not the lexicon fallback. Signed-request matrix: unsigned -> **401**, wrong signature -> **401**, correct HMAC -> **200** with a correct Turkish analysis (`negative` / `bug` / `tr`, keywords `["acinca surekli cokuyor","berbat"]`). Image is 773 MB, under the 1 GB target in ADR-0004.
- **2026-09-02** `Broadcast::channel()` registers on the broadcaster instance of whatever connection was **default at boot** — it reaches the driver through `BroadcastManager::__call`, and channels live on the broadcaster, not the manager. A test that switches `broadcasting.default` afterwards gets a fresh broadcaster with no channels, so every authorization answers **403** — including the ones that should succeed, which silently makes the negative tests pass for the wrong reason. Re-`require` `routes/channels.php` after the switch.
- **2026-09-02** **Route-model binding runs before any middleware that is not in `$middlewarePriority`.** `SubstituteBindings` is in that list, `SetTenantContext` was not, so binding queried the model with no tenant and `CompanyScope` threw: `GET /api/v1/feedbacks/{feedback}` answered **500 instead of the 404** invariant I1 requires. Ordering the route's middleware array does nothing. Fix: `$middleware->prependToPriorityList(SubstituteBindings::class, SetTenantContext::class)`. Same sorting rule as the `SetLocale`/`Authenticate` finding above.
- **2026-09-02** The compose stack mounted only `../backend` into the backend container, so `contracts/` did not exist there and every invariant **I7** contract test **skipped** — the one outcome a contract test must not have. Fixed with `- ../contracts:/srv/contracts:ro`.
- **2026-09-02** `pcntl_fork` + `DB::purge()` in the child **kills the parent's connection**: purge destructs the inherited PDO, whose destructor writes a termination packet down the socket the parent is still using, and the parent dies with "server closed the connection unexpectedly" while rolling back `RefreshDatabase`'s transaction. The child must never touch the inherited handles — point it at a connection name that has never been opened in the process tree, and end on SIGKILL so nothing destructs.
- **2026-09-02** `CarbonImmutable::toDateTimeString()` **drops the offset**. App Store's `2026-08-27T23:40:59-07:00` therefore reached the `timestamptz` column as `23:40:59` UTC — the right wall clock in the wrong zone, **seven hours off the actual instant**, and it would have skewed every trend chart built on `published_at`. Use `toIso8601String()`.
- **2026-09-02** **A cursor watermark must not advance between pages of the same run.** These feeds are newest-first, so a watermark written after page 1 makes every item on page 2 compare as already-seen and the run silently ingests only its first page (5 items became 3). `SyncCursor` now carries `pending` alongside `watermark`: `pending` accumulates during the run, `promoted()` folds it in when the run ends.
- **2026-09-02** **PHPUnit `<env force="true">` cannot override an env var injected by compose `env_file:`.** Laravel's `Env` reader consults the `$_SERVER`/`$_ENV` adapters before the `putenv()` layer that PHPUnit writes. `DB_DATABASE=omnihear` from `backend/.env` therefore won, `RefreshDatabase` ran `migrate:fresh` **against the development database**, and the rate limiter wrote into the Redis instance that holds the Horizon queue. Damage was nil (schema only, no rows) and the schema is back. **`guard-test-db-target` did not catch this** — it inspects the command, and the command looked correct. Durable fix: `backend/tests/bootstrap.php` overwrites `putenv` + `$_ENV` + `$_SERVER` before the autoloader, and allows `test_tmp_*` through for parallel agents.
- **2026-09-02** Middleware **appended** to the `api` group runs *after* `Authenticate`: Laravel sorts route middleware by `$middlewarePriority`, which puts `Authenticate` ahead of `SubstituteBindings` and therefore ahead of anything appended. A 401 with `Accept-Language: tr` silently came back in English. Use `prependToGroup`. Locked by `tests/Feature/Api/LocaleTest.php`.
- **2026-09-02** The backend image shipped **no coverage driver** (`php -m`: neither xdebug nor pcov), so the mandated gate command `php artisan test --coverage --min=80` could not run. Installing at run time is impossible too — `apk del .build-deps` removes phpize, so `pecl install pcov` dies with "`phpize' failed". pcov is now baked into `infra/docker/backend.Dockerfile`.
- **2026-09-02** **Lazy loading does not protect the Angular initial bundle from framework growth.** esbuild splits at *module* granularity: `@angular/core` and `@angular/router` are single fesm modules that live in the initial chunk, and every extra runtime instruction a lazy component uses (host bindings, `RouterLink`, `RouterLinkActive`, the `@defer` loader) survives tree-shaking and is added *there*. Measured in this tree: F1 skeleton alone **245.00 kB**; + one lazy landing page **261.37 kB**; full F2-FE **301.36 kB** raw / **87.63 kB** transfer, against a 250 kB (256.00 kB) budget. Core chunk grew 142.31 -> 151.17 kB and router 76.95 -> 82.90 kB. So the budget is not a code-volume problem and cannot be fixed by moving code into lazy chunks.
- **2026-09-02** `sensitive-log-guard` matched debug calls by plain substring, so `ray(` fired on `toArray(`/`in_array(`/`array(` and `dd(` on `add(`. `toArray(Request $request)` is the mandatory signature of every Laravel API Resource, so the guard warned on files that cannot avoid it. Fixed with a `(?<![\w$])name\s*\(` boundary — `>` is deliberately *not* excluded so `$logger->info(...)` still trips. Self-test is now **116** assertions.
- **2026-09-02** The red CI runs (`33607278315`, `33609920965`) are **not a code failure**. Both died in 2 s with the annotation *"The job was not started because recent account payments have failed or your spending limit needs to be increased"* — the guards job never executed, and every other job shows `skipped` because it `needs: guards`. `node .claude/hooks/__selftest.mjs` is 110/110, exit 0, locally. The repo is **private**, so Actions minutes are billed; a public repo would run them free. Nothing in the workflow needs fixing.
- **2026-09-02** Host `php artisan` no longer runs at all: `composer.json` requires PHP ^8.3 and `vendor/composer/platform_check.php` aborts on the 8.2 host before autoload. Every backend command goes through `docker compose -f infra/docker-compose.dev.yml run --rm backend …`.

## ADRs

`docs/adr/` — 0001 monorepo · 0002 container-authoritative runtime · 0003 Laravel 13 ·
0004 local inference over LLM · 0005 zoneless change detection · 0006 sentiment palette
with lightness separation.
