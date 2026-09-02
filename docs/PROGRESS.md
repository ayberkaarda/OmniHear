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
| **W4** | frontend data layer ∥ D-06 fixture synthesis + F8 Zendesk | **green** — jest **193/193**, build:gate raw 332.22/347, transfer 93.53/105 · pest **813 passed, 3299 assertions**, coverage **98.1%** |
| W5 | frontend data layer 2 (realtime, settings, billing flow, SubscriptionGuard) ∥ settings endpoints + in-app notifications + Laravel OpenAPI + demo seeder | planned |
| W6 | Playwright E2E (register → verify → integrate → sync → inbox → paywall) ∥ READMEs, architecture diagram, docker build in CI | planned |
| W7 | security review (OWASP), history rewrite, public flip | planned |

Parallelism budget, measured rather than assumed: wave 1 finished with three opus
tracks, wave 2 lost all three to a session rate limit, and the difference was track
size. From W3 on: **at most two writing opus tracks plus one narrow track**, each under
roughly 25 files, and every agent files a halfway report so a killed session still
leaves a diagnosable tree.

## Now

**Latest gate**, re-run by the main thread: guards 116/116 · pint 229 files ·
composer validate/audit/platform-reqs clean · pest **813 passed, 3299 assertions**,
coverage **98.1%** · pytest **270 passed** · tokens 63/2/0 · i18n **329/329** ·
typecheck 0 · eslint 0 · jest **193/193** · build:gate raw **332.22/347 kB**,
transfer **93.53/105 kB** · compose 0. CI `33670011911`: five jobs green, backend
690 passed / 4 skipped, ai-service 270 passed — the ONNX weights are cached and
fetched, so the real engine is covered on a clean machine for the first time.

Those 4 skips were the **live cross-service tests**: no analyzer in the backend job.
That is the gap carried since F1 ("nothing in the gate connects two services", and
the first genuinely signed request between them returned 401). The backend job now
starts the analyzer with `SENTIMENT_BACKEND=lexicon` and runs them — the contract
under test is HMAC-over-raw-bytes, the correlation id and the response shape, not
model quality, and the test asserts enum membership rather than a particular label.
Verified: `SENTIMENT_BACKEND=lexicon pytest` is 270 passed.

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

### Open after W4

- **D-06's second half is still open.** The fixtures now hold no real person's data —
  100 synthetic reviews, `reviewer-NNN`, `example.invalid` URIs, the app id replaced
  everywhere including `page-empty-transient.json` — and a test enforces it. But
  `24ac570` still carries the original capture, so **the history rewrite remains
  required before the repository goes public**.
- **Zendesk stores the requester's email** in `feedbacks.raw_payload` (`via.source.from.address`).
  The ingestion PII mask only covers `body`. App Store has the same shape but only a
  nickname; Zendesk is a real address. Spec §8 wants author PII maskable — F9's problem,
  recorded here so it is not discovered later.
- **`CONNECTABLE_PLATFORMS` in the frontend is a hand-copied mirror** of
  `config/connectors.php`; nothing publishes the connectable platforms. The backend added
  Zendesk while the frontend agent was working and it was caught by hand. W5 adds
  `GET /api/v1/integrations/platforms` to the contract.
- Zendesk's shape was synthesised from published documentation; **no part of it was
  verified against a live account.** `contracts/fixtures/platforms/zendesk/README.md`
  separates what the docs confirm from what was inferred — that table is the first place
  to look when a real account exists.

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

## Verified facts

Moved to `docs/LESSONS.md` — append-only, and it outgrew this board's 120-line
cap. Read it at the start of a session; it is where the traps are recorded.

## ADRs

`docs/adr/` — 0001 monorepo · 0002 container-authoritative runtime · 0003 Laravel 13 ·
0004 local inference over LLM · 0005 zoneless change detection · 0006 sentiment palette
with lightness separation.
