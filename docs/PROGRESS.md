# PROGRESS

Updated: 2026-09-04 · Current: **W10 complete; local gate green, E2E awaiting CI** · Spec: `docs/OMNIHEAR-SPEC.md` (its **Errata** section overrides the original line it contradicts)

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
| **W4** | frontend data layer ∥ D-06 fixture synthesis + F8 Zendesk | **green** — jest **193/193**, build:gate raw 332.22/347, transfer 93.53/105 · pest **813 passed, 3299 assertions**, coverage **98.1%** |
| **W5** | realtime, settings, billing flow, SubscriptionGuard ∥ settings endpoints, in-app notifications, Laravel OpenAPI, demo seeder | **green** — jest **284/284**, build:gate raw 335.75/347, transfer 94.09/105 · pest **969 passed, 4535 assertions**, coverage **98.9%** |
| **W6** | Playwright E2E (register → verify → integrate → sync → inbox → paywall) ∥ READMEs, ARCHITECTURE, ADR-0009/0010, ai-service JSON log | **green** — E2E 46.5 s, DB shows 3 analyzed / 2 pending_analysis · pytest 274 |
| **W7** | history rewrite (done) · quota gate + invitations · security review + its seven findings + KVKK PII + self-hosted fonts | **green** — pest **1046 passed, 4870 assertions**, coverage **98.9%** · pytest **282** · jest **290** · build:gate raw 337.88/347, transfer 94.46/105 |
| **W8** | Google Play ∥ Trustpilot connectors (spec §2: 2 of 6 channels → 4), factory/config wiring, `dropdb` guard false positive | **green** — pest **1259 passed, 5524 assertions**, coverage **98.8%**, 349.5 s · guards **122** · unchanged: pytest 282, jest 290, build:gate raw 337.93/347, transfer 94.46/105 |
| **W9** | CI download retry+resume ∥ Horizon queue coverage ∥ reprocess command + Redis KPI cache (invalidated on upgrade and channel deletion too); plus a W8 unordered-`pluck` flake the gate caught | **green** — CI `33861764527`, seven jobs, E2E 11.3 s · pest **1297 passed, 5658 assertions**, coverage **98.9%**, 367.2 s · guards **122** · pytest **295** (was 282), no skips · jest **290** · build:gate raw 337.93/347, transfer 94.46/105 · Horizon measured live: `analysis` queue 5 → 0 |
| **W10** | TOTP 2FA — the API had published `two_factor_enabled` since F2 with no code path able to write it ∥ frontend enrolment and challenge ∥ `actions/*` majors + `waits` on the analysis queue | **local green, E2E awaiting CI** — pest **1404 passed, 6075 assertions**, coverage **98.9%** · guards **122** · jest **323/56** (was 290/55) · i18n **478/478** · build:gate raw 339.66/347, transfer 94.89/105 · pytest 295 unchanged · OpenAPI `--check` matches |

Parallelism budget, measured rather than assumed: wave 1 finished with three opus
tracks, wave 2 lost all three to a session rate limit, and the difference was track
size. From W3 on: **at most two writing opus tracks plus one narrow track**, each under
roughly 25 files, and every agent files a halfway report so a killed session still
leaves a diagnosable tree.

## Now

**Latest gate** is the W9 row below, re-run by the main thread and then confirmed
by CI `33861764527` — seven jobs green, backend 1297 passed on a clean checkout
(identical to local), ai-service 295 with no skips, and the E2E journey in 11.3 s.
`npx playwright test` was the one line that could not run locally: port 4200 was
held by an unrelated project's dev server on this machine. CI settled it.

**A green CI run is not automatically evidence.** The first one reported *534 warnings,
69 passed* for the backend and *11 skipped* for the AI service and went green anyway.
Closed: the backend job copies `.env.example`, `phpunit.xml` sets `failOnWarning` and
`failOnRisky`, the AI job fetches the weights, `pytest -rs` makes a skip visible, and
Node is pinned by `.nvmrc` so the lockfile is written and read by the same npm.
**A phase report cites its CI run id, and a skip or a warning is reported, never
absorbed.**

Contracts are written before dispatch so no track guesses at another's shape:
`docs/contracts/{http-api-v1,backend-core,wave2-seams}.md`. Ownership is disjoint by
top-level directory — the one split that has held across four waves. Where two tracks
must share a directory, the seams document assigns files and the crossing points are
events, so neither side references a class the other has not written yet.

### Still open

- **Three of the four connectors have never touched a live account.** Zendesk, Google
  Play and Trustpilot are synthesised from published documentation, each with a fixture
  README separating documented from inferred; App Store is the only one recorded from a
  real response. Largest gap between "the tests pass" and "it works".
- **The E2E image cache has only ever taken its miss path.** `33861764527` was the first
  run with the key, so it built and saved. W10 deliberately did not touch `ai-service/`,
  so its own CI run is the free proof — the `Load cached ai-service image` step either
  runs or it does not.
- **2FA replay protection is cache-backed, not atomic.** The high-water mark and the
  per-token attempt counter share one cache, so losing it resets both; and the check is
  read-then-write, so two requests carrying the same code both pass. A
  `users.two_factor_last_used_step` column with a conditional `UPDATE` closes both.
  Details and the measured exposure: `docs/contracts/w10-two-factor.md`.
- **Tokens older than 90 days become invalid on deploy** — `expiration` counts from creation. Deliberate; a release note.
- `infra/docker-compose.dev.yml` keeps `${AI_SERVICE_HMAC_SECRET:-dev-only-not-a-real-secret}`
  on purpose: removing it breaks a fresh clone's `docker compose up`, and the value names
  itself. The *application* no longer has a default — that was the finding.

### Closed by CI run `33792058154` (2026-09-03)

First green run including E2E. The journey — register, verify through a real mailbox,
connect a channel, sync, inbox, paywall — passed in **10.6 s** on a clean checkout
against all three services, retiring two entries: the suite had only ever been green on
the machine that wrote it, and realtime delivery had only ever been asserted.

## Open decisions

| id | question | default | decide by |
|---|---|---|---|
| D-01 | `.claude/` + `CLAUDE.md` public in repo? | **decided: keep.** Narrow reading of the no-attribution rule, confirmed by behaviour: the note was written 01:56:27 and `14a51d8` at 01:58:14 deliberately kept them tracked | closed |
| D-05 | Repo visibility | **decided 2026-09-02: stays private; goes public when the project is finished.** Actions billing was unblocked separately, so CI now runs on the private repo | closed |
| D-07 | Angular initial-bundle budget | **decided: two thresholds re-derived from the measured floor (ADR-0007).** raw 320kb in `angular.json`, brotli transfer 100 kB in `scripts/bundle-check.mjs`; Trap 2 rewritten with a ratchet | closed |
| D-08 | **Angular 18 is out of support** (angular.dev: v2-v19 no longer supported). Upgrade is required regardless of the budget | target v22 (Active); also turns `provideExperimentalZonelessChangeDetection` into the stable `provideZonelessChangeDetection` and closes that deviation. Own phase, own gate, after wave 2 | schedule after wave 2 |
| D-09 | Is spec §11 (staging deploy, prod promotion) and are K8s manifests in scope? | **decided 2026-09-03: no — portfolio project, never deployed.** Out of scope, not deferred; ADR-0010 amended, spec erratum E-7. Leaves the completion denominator and gives D-05 a reachable threshold | closed |
| D-06 | Real App Store review text is already in history (`24ac570`). Rewriting the fixtures does not remove it; going public later publishes the history | rewrite fixtures now, history rewrite before the flip (cheapest today at 4 commits) | before going public |

## Known deviations from spec

| what | why | reconcile in |
|---|---|---|
| Laravel 13, not 11 | 11 security-EOL 2026-03-12; two unpatched advisories on §7.1 code paths | resolved — ADR-0003, user approved |
| `provideExperimentalZonelessChangeDetection` was experimental | — | **resolved** — Angular 22 (ADR-0008); the symbol no longer exists, the stable one is in use |
| `config/session.php`, `config/cache.php` lack Laravel 13 hardening defaults | `composer update` does not regenerate skeleton config | **F2, before auth work** |
| Fonts load from Google Fonts CDN | — | **resolved** — self-hosted in W7; a Playwright listener fails the journey if any request leaves localhost |
| initial bundle raw 250 -> 320 -> **347kb**, transfer -> **105 kB** | measured framework floor is 245.00 kB = 95.7% of the old threshold; spec §4 says "hedefi" and the page tree it mandates cannot be built under it | resolved — ADR-0007, re-based by ADR-0008 after the Angular 22 floor rose; transfer 92.09 kB still well under the spec figure |

## Verified facts

Moved to `docs/LESSONS.md` — append-only, and it outgrew this board's 120-line
cap. Read it at the start of a session; it is where the traps are recorded.

## ADRs

`docs/adr/` — 0001 monorepo · 0002 container-authoritative runtime · 0003 Laravel 13 ·
0004 local inference over LLM · 0005 zoneless change detection · 0006 sentiment palette
with lightness separation · 0007 bundle budget from the measured floor · 0008 Angular 22
and threshold rebasing · 0009 feedbacks partitioning deferred · 0010 deliberate scope
exclusions (amended 2026-09-03: deploy and K8s are out of scope, not deferred).
