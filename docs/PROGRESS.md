# PROGRESS

Updated: 2026-09-02 · Current: **wave 2 integrated and green; awaiting approval** · Spec: `omnihear-engineering-prompt (1).md`

> Hard cap: 120 lines. A closed phase collapses to one row. Phase report bodies do
> not live here — their numbers land in the table.

## Phases

Closed phases collapse to one row (the 120-line cap). Full numbers live in the
commit messages; `git log` is the archive.

| # | Scope | Status |
|---|---|---|
| F1 | monorepo skeleton + design-system layer | closed — `24ac570`, `f45cece` |
| F2 · F2-FE · F3 | tenant core + Sanctum auth · app shell, auth, landing, interceptors · ai-service ONNX pipeline + contract artifacts | closed — `24e38a2` |
| F4 · F5 · F6 · F7 | ingestion · analysis, atomic quota, 402 · Stripe · Iyzico | closed — `24e38a2`, `e122c06` |
| CI | first green run in the repo's history, then hardened so a green run means what it says | closed — `633e347`, `680aa39` + the `failOnWarning` / ONNX-in-CI change |
| **W3-A** | D-08 Angular 18 -> 22, floor re-measured, thresholds re-based (ADR-0008) | **green** — jest 135/135 unchanged, `npm ls chokidar` 0, zoneless stable, build:gate raw 328.20/347, transfer 92.09/105 |
| **W3-B** | F2.5 — `verified` enforced, device token revocation, erasure, audit writers, JSON logging, free-domain list | **green** — pest 694 passed, 2303 assertions, coverage 97.9% |
| **W3-C** | cursor-model test hardening (test-only agent, no production code) | **green** — 39 passed, 81 assertions, no production defect found |
| W4 | frontend data layer 1 (overview, inbox, inbox/:id, integrations) ∥ D-06 fixture synthesis + F8 Zendesk | planned |
| W5 | frontend data layer 2 (realtime, settings, billing flow, SubscriptionGuard) ∥ settings endpoints + in-app notifications + Laravel OpenAPI + demo seeder | planned |
| W6 | Playwright E2E (register → verify → integrate → sync → inbox → paywall) ∥ READMEs, architecture diagram, docker build in CI | planned |
| W7 | security review (OWASP), history rewrite, public flip | planned |

Parallelism budget, measured rather than assumed: wave 1 finished with three opus
tracks, wave 2 lost all three to a session rate limit. The difference was track
size. From W3 on: **at most two writing opus tracks plus one narrow sonnet track**,
each track under roughly 25 files, and every agent files a halfway report so a
killed session still leaves a diagnosable tree.

## Now

**Last full gate, re-run by the main thread on a clean tree at `680aa39`:**
guards 116/116 · pint 215 files · composer validate/audit/platform-reqs clean ·
pest **603 passed, 2038 assertions**, coverage **97.9%** · ruff 0 · pytest **270
passed** · tokens 63/2/0 · i18n PASSED 220/220 · typecheck 0 · eslint 0 · jest
**135/135** · build:gate raw 301.36/320 kB, transfer 87.63/100 kB · compose 0.
CI run `33644426386`: all five jobs green.

**A green CI run is not automatically evidence.** That run reported *534 warnings,
69 passed* for the backend (against 603 locally) because `.env` was missing and
`phpunit.xml` had no `failOnWarning`, and *259 passed, 11 skipped* for the AI
service because the ONNX weights are not in the repo — so the sentiment engine the
product actually ships had zero coverage on the only machine that checks a clean
tree. Both are closed: the backend job copies `.env.example`, `phpunit.xml` now
sets `failOnWarning` and `failOnRisky`, the AI job caches and fetches the weights,
and `pytest -rs` makes any remaining skip visible. **A phase report cites its CI
run id, and a skip or a warning is reported, never absorbed.**

Contracts are written before dispatch so no track guesses at another's shape:
`docs/contracts/{http-api-v1,backend-core,wave2-seams}.md`. Ownership is disjoint
by top-level directory — the one split that has held across three waves. Where two
tracks must share a directory, the seams document assigns files and the crossing
points are events, so neither side references a class the other has not written.

## Open decisions

| id | question | default | decide by |
|---|---|---|---|
| D-01 | `.claude/` + `CLAUDE.md` public in repo? | **decided: keep.** Narrow reading of the no-attribution rule, confirmed by behaviour: the note was written 01:56:27 and `14a51d8` at 01:58:14 deliberately kept them tracked | closed |
| D-03 | Move root spec file to `docs/OMNIHEAR-SPEC.md` | move | before next large commit |
| D-04 | Spec §2 erratum: "Laravel 11 (PHP 8.3)" → "Laravel 13 (PHP 8.3)" | apply | F2 start |
| D-05 | Repo visibility | **decided 2026-09-02: stays private; goes public when the project is finished.** Actions billing was unblocked separately, so CI now runs on the private repo | closed |
| D-07 | Angular initial-bundle budget | **decided: two thresholds re-derived from the measured floor (ADR-0007).** raw 320kb in `angular.json`, brotli transfer 100 kB in `scripts/bundle-check.mjs`; Trap 2 rewritten with a ratchet | closed |
| D-08 | **Angular 18 is out of support** (angular.dev: v2-v19 no longer supported). Upgrade is required regardless of the budget | target v22 (Active); also turns `provideExperimentalZonelessChangeDetection` into the stable `provideZonelessChangeDetection` and closes that deviation. Own phase, own gate, after wave 2 | schedule after wave 2 |
| D-06 | Real App Store review text is already in history (`24ac570`). Rewriting the fixtures does not remove it; going public later publishes the history | rewrite fixtures now, history rewrite before the flip (cheapest today at 4 commits) | before going public |

## Known deviations from spec

| what | why | reconcile in |
|---|---|---|
| Laravel 13, not 11 | 11 security-EOL 2026-03-12; two unpatched advisories on §7.1 code paths | resolved — ADR-0003, user approved |
| `provideExperimentalZonelessChangeDetection` was experimental | — | **resolved** — Angular 22 (ADR-0008); the symbol no longer exists, the stable one is in use |
| `config/session.php`, `config/cache.php` lack Laravel 13 hardening defaults | `composer update` does not regenerate skeleton config | **F2, before auth work** |
| Fonts load from Google Fonts CDN | self-hosting deferred | F9 (KVKK) |
| initial bundle raw 250 -> 320 -> **347kb**, transfer -> **105 kB** | measured framework floor is 245.00 kB = 95.7% of the old threshold; spec §4 says "hedefi" and the page tree it mandates cannot be built under it | resolved — ADR-0007, re-based by ADR-0008 after the Angular 22 floor rose; transfer 92.09 kB still well under the spec figure |

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
- **2026-09-02** Two more CI-only failures, both from the same root: **the local run is not colourised and CI's is.** (a) `bundle-check.mjs` reported "could not find the Initial total row" while the bundle was comfortably inside budget — on a GitHub runner the Angular CLI wraps every cell of the size table in CSI sequences, so `| Initial total |` arrives with escapes between the pipe, the label and the numbers. The script now strips ANSI before matching, raises `maxBuffer` to 64 MB (without a TTY the CLI stops collapsing the lazy-chunk list) and checks `spawnSync().error`. Proven against a fixture built from the runner's actual bytes: no match before the strip, `raw=301.36kB transfer=87.63kB` after. (b) `EmptyMiddlePageTest` failed with `AiServiceUnavailableException`: the queue runs sync in tests, so a real `FeedbackIngested` reached the analysis listener and called the analyzer over HTTP — a service that is up in the dev stack and absent in CI. Faked, like every other ingestion test already did.
- **2026-09-02** **CI ran for the first time in this repo's history and found three defects in one run, none of which the local gate can see.** All three are the same class: the local gate runs in a working tree that has gitignored files, CI runs a clean checkout.
  1. `docker compose config` failed with `env file .../backend/.env not found` — compose declares `env_file: ../backend/.env`, which is gitignored. CI now copies `backend/.env.example` first, which also proves the example still covers everything compose reads.
  2. `npm ci` refused: `Missing: chokidar@5.0.0 from lock file`. Root cause is real and predates this — `angular-eslint@22.2.0` pulls `@angular-devkit/core@22.1.6`, which needs `chokidar ^5`, on an Angular **18** tree; `npm ls chokidar` exits 1 with `invalid`. A regenerated lock nests chokidar under the three angular-eslint packages and `npm ci` passes. The version mismatch itself is D-08's to fix; the regenerated lock changed 23 transitive tooling versions and **no** `@angular/*`, `tailwindcss` or `typescript` version, and the bundle came back byte-identical at 301.36 kB / 87.63 kB.
  3. `php artisan test` produced **161 failures and 301 `MissingAppKeyException`** — no `APP_KEY` without a `.env`, so every `encrypted` cast throws, which is the mechanism invariant **I5** rests on. `tests/bootstrap.php` now derives a test key rather than depending on a gitignored file.
- **2026-09-02** **A transiently empty page in the middle of a run erased its items permanently**, and a fully green suite did not see it. The watermark is a high-water mark on `published_at`: promoting it after skipping a page puts everything that was on that page below it, so `alreadySeen()` rejects those items on every later run — not retried, gone. Proven with `tests/Feature/Ingestion/EmptyMiddlePageTest.php` (three pages, middle one blank on run 1: `middle` never arrived even on a healthy run 2), then fixed. Promotion moved out of the connectors and into `IngestionRunner`, which is the only party that sees the whole run, and is gated on `! $page->hasMore && ! $sawEmptyPage`. **`$capped` alone is the wrong test**: the App Store connector reports `hasMore=false` exactly as it reaches the platform's depth limit of 10, tripping the runner's cap in the same iteration, and that run *is* complete — what must block promotion is the runner cutting a run short while the connector still had more. Found by review, not by the suite; the existing tests only covered an empty *first* page, which is the recoverable case.
- **2026-09-02** `Broadcast::channel()` registers on the broadcaster instance of whatever connection was **default at boot** — it reaches the driver through `BroadcastManager::__call`, and channels live on the broadcaster, not the manager. A test that switches `broadcasting.default` afterwards gets a fresh broadcaster with no channels, so every authorization answers **403** — including the ones that should succeed, which silently makes the negative tests pass for the wrong reason. Re-`require` `routes/channels.php` after the switch.
- **2026-09-02** **Route-model binding runs before any middleware that is not in `$middlewarePriority`.** `SubstituteBindings` is in that list, `SetTenantContext` was not, so binding queried the model with no tenant and `CompanyScope` threw: `GET /api/v1/feedbacks/{feedback}` answered **500 instead of the 404** invariant I1 requires. Ordering the route's middleware array does nothing. Fix: `$middleware->prependToPriorityList(SubstituteBindings::class, SetTenantContext::class)`. Same sorting rule as the `SetLocale`/`Authenticate` finding above.
- **2026-09-02** The compose stack mounted only `../backend` into the backend container, so `contracts/` did not exist there and every invariant **I7** contract test **skipped** — the one outcome a contract test must not have. Fixed with `- ../contracts:/srv/contracts:ro`.
- **2026-09-02** `pcntl_fork` + `DB::purge()` in the child **kills the parent's connection**: purge destructs the inherited PDO, whose destructor writes a termination packet down the socket the parent is still using, and the parent dies with "server closed the connection unexpectedly" while rolling back `RefreshDatabase`'s transaction. The child must never touch the inherited handles — point it at a connection name that has never been opened in the process tree, and end on SIGKILL so nothing destructs.
- **2026-09-02** `CarbonImmutable::toDateTimeString()` **drops the offset**. App Store's `2026-08-27T23:40:59-07:00` therefore reached the `timestamptz` column as `23:40:59` UTC — the right wall clock in the wrong zone, **seven hours off the actual instant**, and it would have skewed every trend chart built on `published_at`. Use `toIso8601String()`.
- **2026-09-02** **A cursor watermark must not advance between pages of the same run.** These feeds are newest-first, so a watermark written after page 1 makes every item on page 2 compare as already-seen and the run silently ingests only its first page (5 items became 3). `SyncCursor` now carries `pending` alongside `watermark`: `pending` accumulates during the run, `promoted()` folds it in when the run ends.
- **2026-09-02** **`pusher-js@8.6.0` is 15.62 kB brotli and `laravel-echo@2.4.0` is 2.54 kB** (measured from jsDelivr, brotli-11; consistent with Bundlephobia's gzip figures). Together they exceed the entire post-ADR-0008 transfer headroom of 12.91 kB, so realtime cannot enter the initial bundle — W5 loads it through `import()` as its own lazy chunk, and `angular.json` gains an `anyScript` budget when it lands.
- **2026-09-02** ADR-0007's ratchet clause was **unsatisfiable from the day it was written**. It required floor >= 90% of the threshold, but a threshold derived as `floor + allowance` gives `floor / threshold = 1 - allowance / threshold`, which is 75% at 80/320; the ratio at the time was 245.00/320 = **76.6%**. The rule rejected its own thresholds. Replaced (ADR-0008) with an attribution-based classification: a raise needs the per-source table to show the `src/` line did not grow.
- **2026-09-02** **Test database isolation never worked, and the test meant to prove it passed anyway.** Two layers. First, compose injects `backend/.env` via `env_file:`, so `DB_DATABASE=omnihear` existed as a real environment variable and PHPUnit's `<env force="true">` could not beat it (Laravel's `Env` reader consults `$_SERVER`/`$_ENV` before the `putenv()` layer PHPUnit writes). `RefreshDatabase` ran `migrate:fresh` against the **development** database once (schema only, no rows). `tests/bootstrap.php` closed that by writing all three layers before the autoloader. Second — the part that hid for two waves — `phpunit.xml` *also* declared `DB_DATABASE`, and PHPUnit applies `<env force="true">` **after** the bootstrap runs, so the bootstrap's choice was overwritten every time and its `test_tmp_` branch was dead code. **Six agents across two waves each believed they had a private database and were all writing to `omnihear_test` at once.** Proven: `test_tmp_f2h` finished a full wave with **zero tables** while `omnihear_test` had 17. Fixed by deleting the line from `phpunit.xml`, leaving `tests/bootstrap.php` the single authority; verified with `-e DB_DATABASE=test_tmp_f4`, which took that database from 0 to 17 tables. `DatabaseIsolationTest` now asserts the connected database **equals the one requested** — asserting only the *shape* of the name is what let it pass through all of this. `guard-test-db-target` was taught that the bootstrap is the authority, since it had been checking `phpunit.xml` for the very line that had to go.
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
