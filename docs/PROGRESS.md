# PROGRESS

Updated: 2026-09-02 · Current: **F1 complete, awaiting approval** · Next: F2 · Spec: `omnihear-engineering-prompt (1).md`

> Hard cap: 120 lines. A closed phase collapses to one row. Phase report bodies do
> not live here — their numbers land in the table.

## Phases

| # | Scope | Status | Gate evidence (numbers only) |
|---|---|---|---|
| F1 | monorepo skeleton + design-system layer | **green, awaiting approval** | guards 110/110 · tokens 63/2/0 · i18n PASSED · typecheck 0 · eslint 0 · jest 49/49 · build 240.53 kB exit 0 · compose 0 · audit clean · pint 30 files · pest 4/11 · ruff 0 · pytest 13/13 |
| F2 | tenant core (companies, users, global scope, policies, Sanctum) | not started | — |
| F3 | ai-service real analyzer (local pipeline) + contract | not started | — |
| F4 | ingestion (FixtureConnector + AppStoreConnector) | not started | — |
| F5 | analysis pipeline + atomic quota + 402 | not started | — |
| F6 | payments — Stripe | not started | — |
| F7 | payments — Iyzico | not started | — |
| F8 | ingestion — Zendesk | not started | — |

Note: the design-system token layer and the six UI components belong to F7 in the
original plan. They landed in F1 at the user's request, after a design authored in
Claude Design was imported. The phase order is otherwise unchanged.

## Now

- F1 gate is green. Awaiting user approval to close and start F2.
- Commit not yet made; the user commits, this session only drafts messages.

## Open decisions

| id | question | default | decide by |
|---|---|---|---|
| D-01 | `.claude/` + `CLAUDE.md` public in repo? | keep (ADR rationale: config, not authorship) | F2 start |
| D-03 | Move root spec file to `docs/OMNIHEAR-SPEC.md` | move | before next large commit |
| D-04 | Spec §2 erratum: "Laravel 11 (PHP 8.3)" → "Laravel 13 (PHP 8.3)" | apply | F2 start |

## Known deviations from spec

| what | why | reconcile in |
|---|---|---|
| Laravel 13, not 11 | 11 security-EOL 2026-03-12; two unpatched advisories on §7.1 code paths | resolved — ADR-0003, user approved |
| `provideExperimentalZonelessChangeDetection` is experimental in Angular 18 | stabilised unchanged in 19/20; one-line migration | whenever Angular is upgraded |
| `config/session.php`, `config/cache.php` lack Laravel 13 hardening defaults | `composer update` does not regenerate skeleton config | **F2, before auth work** |
| Fonts load from Google Fonts CDN | self-hosting deferred | F9 (KVKK) |

## Verified facts (append-only, dated — do not rediscover)

- **2026-09-02** `tsc -p tsconfig.app.json --noEmit` **does not check unrouted files.** The CLI generates `files: ["src/main.ts"]`, so anything unreachable from the entry point is skipped and the command exits 0. Proven: a deliberate `const x: number = "string"` in `shared/ui/` went unreported. Gate now uses `npm run typecheck` (`tsconfig.typecheck.json`). The build still uses `tsconfig.app.json`, so bundle output is unaffected.
- **2026-09-02** Same blind spot hit i18n: 23 marked strings existed with 4 extracted units, and nothing caught it — `ng extract-i18n` cannot see unrouted components either. `i18n:check` rule 5 now compares the source `@@id` count against the trans-unit count.
- **2026-09-02** App Store RSS review feed is live, needs no credentials. Depth limit **10** — `page=11` returns **HTTP 400** with a gzip'd body `CustomerReviews RSS page depth is limited to 10` (not corrupt JSON; read the status code). Pages come back **empty intermittently** — `page=1` returned 0 entries once, then 50 on five retries — so "empty page = end of stream" silently loses data. Fixtures in `contracts/fixtures/platforms/appstore/`.
- **2026-09-02** Iyzico: sandbox is self-serve, signature is `X-IYZ-SIGNATURE-V3` only, subscription webhooks carry **no native event id** (derive `webhook_events.event_id`), retries stop after **3 attempts**.
- **2026-09-02** Host differs from spec: PHP 8.2.12 (no `pcntl`/`posix` on Windows, so Horizon cannot run there), Python 3.14.7, no local `psql`. Containers are authoritative (ADR-0002).
- **2026-09-02** Removing zone.js: 261.52 kB → 226.52 kB initial (polyfills 36.36 → 1.84 kB). `--localize=false` was worth 0.65 kB, `withFetch()` nothing.
- **2026-09-02** An **uncalibrated** colour-blindness simulation produced wrong numbers and nearly drove the opposite palette decision. `tokens-check` now runs calibration assertions first and aborts everything if they fail. Do not remove them.
- **2026-09-02** Three defects survived a fully green gate because **nothing in it connects two services**. An empty named volume over `backend/vendor` shadowed the host directory and killed every `artisan` command; `docker compose config -q` passed anyway (valid syntax, broken behaviour). The HMAC secret differed between backend (`REPLACE_ME` from `.env`) and ai-service (compose default), so the first real signed request returned **401 INVALID_SIGNATURE** — both sides were green in isolation and the contract test uses fixtures, not a live call. Verified by hand afterwards: unsigned → 401, mismatched → 401, matched → 200 with the correct response shape. **A live smoke test belongs in the gate** (proposed, not yet added — needs approval).
- **2026-09-02** Host `php artisan` no longer runs at all: `composer.json` requires PHP ^8.3 and `vendor/composer/platform_check.php` aborts on the 8.2 host before autoload. Every backend command goes through `docker compose -f infra/docker-compose.dev.yml run --rm backend …`.

## ADRs

`docs/adr/` — 0001 monorepo · 0002 container-authoritative runtime · 0003 Laravel 13 ·
0004 local inference over LLM · 0005 zoneless change detection · 0006 sentiment palette
with lightness separation.
