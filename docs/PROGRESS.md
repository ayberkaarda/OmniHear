# PROGRESS

Updated: 2026-09-03 · Current: **W7 complete; every gate green** · Spec: `docs/OMNIHEAR-SPEC.md` (its **Errata** section overrides the original line it contradicts)

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

### Still open

- **Realtime has never run against a real Reverb.** `REVERB_APP_KEY` is `REPLACE_ME`
  in both env files, so the client reports `disabled`. Three files away: compose env
  for `reverb`/`backend`, the SPA's development environment, one E2E step. This is
  the last item on the "done" line — a headline feature (spec §4 "Reaktif akış") that
  has never been executed cannot be filed as a recorded limitation.
- **Playwright was green for the implementing agent, not re-run by the main thread.**
  It passed with the two new assertions — the browser reading `X-Quota-Remaining`
  through CORS, and a guard that fails if any request leaves localhost — and emptying
  `exposed_headers` was shown to turn it red. The re-run hit the 5-per-hour
  registration limiter, which is the suite's own documented failure message.
- **IBM Plex is self-hosted but unused.** `tailwind.config.js` never overrode
  `fontFamily`, so the CDN request bought nothing. The faces cost no bytes until
  something asks for them; either wire them into the theme or delete the six files.
- **Tokens older than 90 days become invalid on deploy** — `expiration` counts from
  creation. Deliberate, but it is a release note.
- **Zendesk's shape was never verified against a live account.** Its fixture README
  separates what the documentation confirms from what was inferred.
- `infra/docker-compose.dev.yml` keeps `${AI_SERVICE_HMAC_SECRET:-dev-only-not-a-real-secret}`
  on purpose: removing it breaks `docker compose up` for a fresh clone, and the value
  names itself. The *application* no longer has a default — that was the finding.

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
| Fonts load from Google Fonts CDN | — | **resolved** — self-hosted in W7; a Playwright listener fails the journey if any request leaves localhost |
| initial bundle raw 250 -> 320 -> **347kb**, transfer -> **105 kB** | measured framework floor is 245.00 kB = 95.7% of the old threshold; spec §4 says "hedefi" and the page tree it mandates cannot be built under it | resolved — ADR-0007, re-based by ADR-0008 after the Angular 22 floor rose; transfer 92.09 kB still well under the spec figure |

## Verified facts

Moved to `docs/LESSONS.md` — append-only, and it outgrew this board's 120-line
cap. Read it at the start of a session; it is where the traps are recorded.

## ADRs

`docs/adr/` — 0001 monorepo · 0002 container-authoritative runtime · 0003 Laravel 13 ·
0004 local inference over LLM · 0005 zoneless change detection · 0006 sentiment palette
with lightness separation.
