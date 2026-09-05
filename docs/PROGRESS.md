# PROGRESS

Updated: 2026-09-05 · Current: **W14 green (CI `33979566100`); spec complete, security reviewed, tooling ported** · Spec: `docs/OMNIHEAR-SPEC.md` (its **Errata** section overrides the original line it contradicts)

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
| **W3-C** | cursor-model test hardening (test-only workstream, no production code) | **green** — 39 passed, 81 assertions, no production defect found |
| **W4** | frontend data layer ∥ D-06 fixture synthesis + F8 Zendesk | **green** — jest **193/193**, build:gate raw 332.22/347, transfer 93.53/105 · pest **813 passed, 3299 assertions**, coverage **98.1%** |
| **W5** | realtime, settings, billing flow, SubscriptionGuard ∥ settings endpoints, in-app notifications, Laravel OpenAPI, demo seeder | **green** — jest **284/284**, build:gate raw 335.75/347, transfer 94.09/105 · pest **969 passed, 4535 assertions**, coverage **98.9%** |
| **W6** | Playwright E2E (register → verify → integrate → sync → inbox → paywall) ∥ READMEs, ARCHITECTURE, ADR-0009/0010, ai-service JSON log | **green** — E2E 46.5 s, DB shows 3 analyzed / 2 pending_analysis · pytest 274 |
| **W7** | history rewrite (done) · quota gate + invitations · security review + its seven findings + KVKK PII + self-hosted fonts | **green** — pest **1046 passed, 4870 assertions**, coverage **98.9%** · pytest **282** · jest **290** · build:gate raw 337.88/347, transfer 94.46/105 |
| **W8** | Google Play ∥ Trustpilot connectors (spec §2: 2 of 6 channels → 4), factory/config wiring, `dropdb` guard false positive | **green** — pest **1259 passed, 5524 assertions**, coverage **98.8%**, 349.5 s · guards **122** · unchanged: pytest 282, jest 290, build:gate raw 337.93/347, transfer 94.46/105 |
| **W9** | CI download retry+resume ∥ Horizon queue coverage ∥ reprocess command + Redis KPI cache (invalidated on upgrade and channel deletion too); plus a W8 unordered-`pluck` flake the gate caught | **green** — CI `33861764527`, seven jobs, E2E 11.3 s · pest **1297 passed, 5658 assertions**, coverage **98.9%**, 367.2 s · guards **122** · pytest **295** (was 282), no skips · jest **290** · build:gate raw 337.93/347, transfer 94.46/105 · Horizon measured live: `analysis` queue 5 → 0 |
| **W10** | TOTP 2FA — the API had published `two_factor_enabled` since F2 with no code path able to write it ∥ frontend enrolment and challenge ∥ `actions/*` majors + `waits` on the analysis queue | **green** — CI `33873188756`, seven jobs, E2E 56.4 s with the 2FA leg (was 11.3 s) · pest **1404 passed, 6075 assertions**, coverage **98.9%** · guards **122** · jest **323/56** (was 290/55) · i18n **478/478** · build:gate raw 339.66/347, transfer 94.89/105 · pytest 295 unchanged · OpenAPI `--check` matches |
| **W11** | email connector over JMAP (spec §2: 4 of 6 channels → **5**) ∥ the 2FA replay mark moves from cache to a `users` column, making the check atomic | **green** — CI `33903711379`, seven jobs, E2E 58.3 s, image cache hit for the second run running · pest **1512 passed, 6419 assertions** (identical on a clean checkout), coverage **98.9%** · guards **122** · i18n **480/480** · build:gate raw 339.66/347, transfer 94.88/105 (unmoved) · the replay race test fails 5-of-5 against read-then-write and passes 1-of-5 with the conditional `UPDATE` |
| **W12** | social connector over the Mastodon hashtag timeline — **6 of 6 channels**, spec §2 complete ∥ MIT licence, the public-facing docs brought to the truth, and the fixture-provenance promise finally tested for Zendesk and Google Play | **green** — CI `33913560354` · pest **1648 passed, 7009 assertions**, coverage **98.9%** · guards **122** · jest **323/56** · i18n **482/482** · build:gate raw 339.66/347, transfer 94.90/105 (unmoved) · **recorded live** from `mastodon.social`, no account: the second channel after App Store to meet a real server |
| **W13** | closure round: the theme-switch contrast flash found by *looking* at the product, 403 → `Misconfigured`, six raw-key labels, bilingual README with screenshots, D-13 recorded | **green** — CI `33920031959` · pest **1649 passed, 7017 assertions**, coverage **98.9%** · guards **122** · jest **325/56** · i18n **488/488** · pytest 295 · tokens 63/2/0 · build:gate raw 340.70/347, transfer 95.03/105 (unmoved) · **E2E ran locally for the first time**, and found the dev database two migrations behind |
| **W14** | adversarial security review of the whole tree, then remediation: SSRF via tenant-pasted connector URLs (the headline), the 2FA challenge counter made atomic and per-account, the `pro` quota that a payment never raised, a password gate on 2FA-enrol and email-change, plus smaller fixes; crown-jewel invariants probed and held | **green** — CI `33979566100`, six jobs (the guards job folded into the backend suite as the architecture tests) · pest **1759 passed, 7223 assertions**, coverage **98.7%** · jest **326/56** · i18n **488/488** · pytest **298** · build:gate raw 340.71/347, transfer 95.03/105 (unmoved) · posture recorded in ADR-0011 |

Parallelism budget, measured rather than assumed: the first round finished with three
parallel workstreams, the second lost all three to a session limit, and the difference was
workstream size. From W3 on: **at most two writing workstreams plus one narrow one**, each under
roughly 25 files, and every workstream files a halfway report so an interrupted session still
leaves a diagnosable tree.

## Now

**Latest gate** is the W14 row above, confirmed by CI `33979566100` — six jobs
now that the guards job has folded into the backend suite as the architecture
tests. The end-to-end journey can run on this machine (leave `CI` unset and
Playwright reuses the compose frontend on 4200 rather than starting its own);
its first local run each phase has twice caught the dev database sitting a
migration behind, which CI, building schema from scratch, cannot see. CI remains
the authority.

**A green CI run is not automatically evidence** — the first reported 534 warnings and
went green anyway. Hardened (`failOnWarning`/`failOnRisky`, weights fetched, `pytest -rs`,
`.nvmrc`-pinned Node); the details are in `docs/LESSONS.md`. **A phase report cites its
CI run id, and a skip or a warning is reported, never absorbed.**

Contracts are written before implementation so no workstream guesses at another's shape:
`docs/contracts/{http-api-v1,backend-core,wave2-seams}.md`. Ownership is disjoint by
top-level directory — the one split that has held across four rounds. Where two workstreams
must share a directory, the seams document assigns files and the crossing points are
events, so neither side references a class the other has not written yet.

### Still open

- **Four of the six channels have never touched a live account.** Zendesk, Google Play,
  Trustpilot and email are synthesised from published documentation, each with a fixture
  README separating documented from inferred. **Two are recorded from real responses**:
  App Store since F4, and social since W12 — `mastodon.social` needs no account, so that
  one was free. Still the largest gap between "the tests pass" and "it works". Email is
  the only one that can close cheaply: its README lists, in priority order, exactly what
  a Fastmail-trial recording would settle, error status codes first. The other three
  each want a paid or approved account, and each already declares itself synthetic.
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
| D-14 | Security review remediation scope | **decided 2026-09-05: W14 fixes SSRF (B1), the 2FA challenge counter (B3), the pro quota (B4), the password asymmetry (B9), Stripe tolerance (B12), the validation-error input echo, and the PII mask residue; the rest — B2/B6/B10/B14-17, frontend CSP and token storage, DNS-rebinding residual — are recorded in ADR-0011 with a trigger and a fix shape each, deferred because D-09 makes production-shaped controls untestable.** The probed-and-sound surfaces (I1, I7, ability boundary, injection, webhook signature) are recorded there too, as the portfolio signal | closed |
| D-13 | Editor-tooling config kept in-repo, or removed for the public release | **decided and done, W14.** The project's engineering rules and playbooks stay — the rules file became `CONTRIBUTING.md` (invariants, the regression gate, Traps 1-4, the destructive-command procedure) and the nine skill playbooks moved to `docs/playbooks/`. The two authoring-time guards worth keeping became gate rules that survive without the tooling: the tenant-scope and log-sensitivity checks are Pest architecture tests under `backend/tests/Feature/Architecture/` (they defend I1 and I5 and are editor-agnostic), and ruff `T20` replaces the Python `print` ban; the CI guards job folded into the backend job. About thirty code and config files referenced the old rules file and were repointed. The commit **bodies** of thirteen older commits still carry the vocabulary; a `filter-repo` rewrite to scrub them was **considered and declined** — the working tree is clean, so the trace remaining is only in historical messages, and rewriting every hash on a public repo (nine of them cited across the docs) to word-substitute a decision's reasoning is a cost out of proportion to a few words in `git log`. If the trace ever has to go, the rewrite is the maintainer's call | closed |
| D-01 | Editor-tooling config public in-repo? | **superseded by D-13** — the config is removed and its durable content ported to `CONTRIBUTING.md`, `docs/playbooks/` and the architecture tests | closed |
| D-05 | Repo visibility | **superseded by fact, 2026-09-04.** The row said "stays private until finished" and `docs/LESSONS.md` said Actions minutes were billed because of it. Neither is true: `gh repo view` reports `visibility: PUBLIC`, `isPrivate: false`, since `createdAt 2026-09-03T08:52:06Z`. The W7 rewrite re-created the repository and the new one was public from its first commit — which is also why Actions has been free since W8. Everything that was being held for "before the flip" has been published all along | closed |
| D-07 | Angular initial-bundle budget | **decided: two thresholds re-derived from the measured floor (ADR-0007).** raw 320kb in `angular.json`, brotli transfer 100 kB in `scripts/bundle-check.mjs`; Trap 2 rewritten with a ratchet | closed |
| D-08 | **Angular 18 is out of support** (angular.dev: v2-v19 no longer supported). Upgrade is required regardless of the budget | target v22 (Active); also turns `provideExperimentalZonelessChangeDetection` into the stable `provideZonelessChangeDetection` and closes that deviation. Own phase, own gate, after phase group 2 | schedule after phase group 2 |
| D-09 | Is spec §11 (staging deploy, prod promotion) and are K8s manifests in scope? | **decided 2026-09-03: no — portfolio project, never deployed.** Out of scope, not deferred; ADR-0010 amended, spec erratum E-7. Leaves the completion denominator and gives D-05 a reachable threshold | closed |
| D-06 | Real App Store review text was in history (`24ac570`) | **done and verified 2026-09-04.** The rewrite landed: `24ac570`, `24e38a2` and `e122c06` are unreachable from every ref and absent from `origin`; they survive only as dangling local objects awaiting `gc`, which are never pushed. First reachable fixture commit `7d0e927` carries `reviewer-NNN` / `example.invalid` only, and a `git log -S` sweep for key material finds nothing but hook regexes | closed |

## Known deviations from spec

| what | why | reconcile in |
|---|---|---|
| Laravel 13, not 11 | 11 security-EOL 2026-03-12; two unpatched advisories on §7.1 code paths | resolved — ADR-0003, user approved |
| `provideExperimentalZonelessChangeDetection` was experimental | — | **resolved** — Angular 22 (ADR-0008); the symbol no longer exists, the stable one is in use |
| `config/session.php`, `config/cache.php` lack Laravel 13 hardening defaults | `composer update` does not regenerate skeleton config | **F2, before auth work** |
| Fonts load from Google Fonts CDN | — | **resolved** — self-hosted in W7; a Playwright listener fails the journey if any request leaves localhost |
| initial bundle raw 250 -> 320 -> **347kb**, transfer -> **105 kB** | measured framework floor is 245.00 kB = 95.7% of the old threshold; spec §4 says "hedefi" and the page tree it mandates cannot be built under it | resolved — ADR-0007, re-based by ADR-0008 after the Angular 22 floor rose; transfer 92.09 kB still well under the spec figure |
| email (JMAP) fixtures are **recorded**, but three of their branches are not | recorded 2026-09-05 against a live Fastmail account, envelope-real / content-synthetic. What the account could not supply stays inferred and is marked as such in the provenance README: **403** (a trial account has no second account to be refused), **429** (deliberately not provoked — exhausting a live service's budget to photograph an error body is abuse), and **`preview` on an HTML-only message** (every message in the account carried a `text/plain` alternative, so the HTML branch is still authored). The watched folder was empty, so message placement inside the recorded envelopes is authored too | open — settles only if a second account, or a mailbox with HTML-only mail, becomes available. Not a blocker: all three branches have fixtures and tests, they are just not measured |

## Verified facts

Moved to `docs/LESSONS.md` — append-only, and it outgrew this board's 120-line
cap. Read it at the start of a session; it is where the traps are recorded.

## ADRs

`docs/adr/` — 0001 monorepo · 0002 container-authoritative runtime · 0003 Laravel 13 ·
0004 local inference over LLM · 0005 zoneless change detection · 0006 sentiment palette
with lightness separation · 0007 bundle budget from the measured floor · 0008 Angular 22
and threshold rebasing · 0009 feedbacks partitioning deferred · 0011 security posture and
deferred hardening · 0010 deliberate scope
exclusions (amended 2026-09-03: deploy and K8s are out of scope, not deferred).
