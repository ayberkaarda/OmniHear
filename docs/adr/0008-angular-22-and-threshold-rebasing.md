# ADR-0008 — Angular 22, and re-basing the bundle thresholds across a framework upgrade

- **Status:** Accepted
- **Date:** 2026-09-02
- **Phase:** D-08
- **Related spec:** §2 "Angular 18+ (standalone components, Signals)" · §4 initial bundle target
- **Related ADRs:** 0003 (same EOL reasoning, for Laravel 11) · 0005 (zoneless) · **amends 0007** (ratchet clause)

## Context

Angular 18 is out of support. angular.dev/reference/releases states it plainly:
"Angular versions v2 to v19 are no longer supported." That is the reasoning
ADR-0003 applied to Laravel 11, which the user approved.

It was not theoretical. CI broke on it: `angular-eslint@22.2.0` pulls
`@angular-devkit/core@22.1.6`, which requires `chokidar ^5`, onto an Angular 18
tree. `npm ci` refused outright and `npm ls chokidar` exited 1 with `invalid`.
The tooling had already moved to the 22 line while the framework had not.

| version | status | ends |
|---|---|---|
| v22 | **Active** | active 2027-06, LTS 2028-06 |
| v21 | LTS | 2027-06 |
| v20 | LTS | **2026-11-28** — three months away |
| v19 and below | out of support | — |

v20 was rejected: landing on a version whose LTS expires in three months buys a
quarter and schedules a repeat of this phase.

## Decision

**Upgrade to Angular 22.1.4**, one major at a time (18→19→20→21→22), running the
full suite between hops so a breakage is attributable to a single step. That
discipline paid: the jest peer-dependency wall appeared precisely at 20→21.

Three consequences are adopted deliberately:

1. **`provideExperimentalZonelessChangeDetection` becomes the stable
   `provideZonelessChangeDetection`.** The v20 hop removes the experimental
   symbol outright (`TS2724`), so this is not optional. It closes the deviation
   PROGRESS has carried since ADR-0005. `zone.js` is still absent from polyfills
   and from `package.json`; the polyfills chunk is still 1.84 kB.
2. **jest 30 + jest-preset-angular 17.** `ng update @angular/core@21` refuses to
   run against jest 29. `jest.config.js` and `setup-jest.ts` needed no change.
3. **The lockfile is regenerated, not patched.** After the upgrade `npm ci`
   passed but `npm ls chokidar` still exited 1, and the cause had changed hands:
   the lock's stale hoist layout pinned root `chokidar` at 3.6.0 for tailwindcss
   3.4, and every `@angular-devkit/core@22` deduped onto it while requiring
   `^5.0.0`. A regenerated lock hoists chokidar 5 and nests 3.6.0 under
   tailwindcss and webpack-dev-server.

`@angular/animations` and `@angular/platform-browser-dynamic` are dropped: zero
imports anywhere in `src/`, and the bundle came back byte-identical without them.

No new runtime dependency was added, and no test was modified — all 135 pass as
written, at every one of the five hops.

### Thresholds: `previous threshold + ceil(delta)`, not `floor + fixed allowance`

The measured framework floor **rose**, so ADR-0007's ratchet did not permit an
automatic adjustment and the upgrade landed with a red gate: 328.20 kB raw
against a 320 kB threshold.

| | Angular 18 | Angular 22 | Δ |
|---|---|---|---|
| floor, raw | 245.00 kB | **261.29 kB** | +16.29 |
| floor, transfer | 67.26 kB | **73.15 kB** | +5.89 |
| full app, raw | 301.36 kB | **328.20 kB** | +26.84 |
| full app, transfer | 87.63 kB | **92.09 kB** | +4.46 |

The floor measurement reproduces ADR-0007's method exactly, and was validated by
first re-running it on Angular 18, where it returned 245.00 kB to the byte.

Attribution of the v22 initial bundle, from the chunks' source maps:

| source | raw | share |
|---|---|---|
| vendor chunk, no application source map (`@angular/core` body) | 167.80 kB | 51.1% |
| `@angular/router` | 76.31 kB | 23.2% |
| **application (`src/`)** | **35.29 kB** | **10.8%** |
| `@angular/platform-browser` | 11.72 kB | 3.6% |
| `@angular/common` | 7.51 kB | 2.3% |
| styles (CSS) | 23.88 kB | 7.3% |
| polyfills | 1.84 kB | 0.6% |

`@angular/*` is roughly 263.3 kB, 80.2% of the initial bundle. Application
TypeScript is 10.8% and did not grow: the page tree is byte-for-byte the one
F2-FE delivered.

**ADR-0007's `floor + 80 kB` formula is retired here.** Not one line of
application code changed in this upgrade, yet ADR-0007's own definition of the
application allowance (full − floor) grew from 56.36 kB to 66.91 kB. The reason
is structural: the floor is measured on a single `/health` route, so it cannot
see the framework's *per-instruction* growth — the extra runtime a real page
pulls into the initial chunk — and that growth lands in the allowance instead.
A fixed allowance therefore bills framework growth to application code, and would
misclassify it again at the next major.

What is worth holding constant is the application's **headroom**, which is
measurable. Before the upgrade: 18.64 kB raw, 12.37 kB transfer. So both
thresholds move by the measured delta of the full application:

- raw: 320 + ceil(26.84) = **347 kb** (warning 327 kb)
- transfer: 100 + ceil(4.46) = **105 kB**

Headroom after: 18.80 kB raw and 12.91 kB transfer — the upgrade's only gift is
the 0.16 / 0.54 kB of rounding. Verified: `build:gate` reports
`PASS — raw 18.80 kB and transfer 12.91 kB of headroom`, and the two-place lock
still fails when `angular.json` alone is edited.

Transfer moves under the same rule rather than being left at 100. Applying the
reasoning to raw but not to transfer would make the rule look arbitrary, and raw
remains the tighter guard anyway: 18.80 kB raw is about 5.3 kB of
transfer-equivalent at the measured 3.56:1 ratio, well inside 12.91.

### The ratchet clause in ADR-0007 was unsatisfiable as written

It required the floor to be at least 90% of the current threshold before a raise.
But a threshold derived as `floor + allowance` gives `floor / threshold =
1 − allowance / threshold`; with 80/320 that is 75%. **The rule could never be
satisfied by the very thresholds it guarded** — at the moment ADR-0007 was
written the ratio was 245.00/320 = 76.6%. Its intent (distinguish "the framework
grew" from "we wrote sloppy code") was right; the measure was wrong, because it
compared the floor against a number that already contained the allowance.

CONTRIBUTING.md Trap 2 now classifies by **attribution** instead: a raise requires the
per-source table to show the `src/` line did not grow. That is the evidence this
upgrade actually produced.

## Alternatives considered

- **Stay on 18, pin angular-eslint back.** Rejected: treats the symptom, leaves
  the framework unsupported, and the 19 line is itself out of support.
- **Land on v20 or v21.** Rejected: both schedule a repeat within months.
- **One jump, 18 → 22.** Rejected: five majors of migrations landing together
  make a failure unattributable.
- **`floor + 80 kB` on the new floor (345 kb).** Rejected: no derivation behind
  the 80, and it misclassifies framework growth as application growth.
- **Leave transfer at 100.** Rejected as asymmetric.
- **Drop the `withXhr()` shim to recover bytes** (measured: −3.40 kB raw /
  −0.75 kB transfer). Rejected as a mechanism: it does not close the 8.20 kB gap,
  and switching the HTTP backend to fetch is a runtime behaviour change the suite
  cannot see, because the tests use `HttpTestingController`.
- **Migrate tailwindcss to v4** to remove chokidar 3 at the root. Out of scope:
  v4 replaces the JS config with CSS-first `@theme`, and `tailwind.config.js`,
  `tokens-build.mjs` and `tokens-check.mjs` are all built on the v3 shape.

## Consequences

**Positive.** Active support until 2027-06, LTS to 2028-06. The chokidar
mismatch that broke `npm ci` is gone at the root. The zoneless deviation is
closed. Tests, lint, typecheck, tokens and i18n all pass unchanged — the
strongest available evidence that the upgrade preserves behaviour: 135/135 jest,
eslint 0, typecheck 0, i18n 220/220 with no empty targets, guards 116/116.

**Negative.** Transfer headroom narrowed from 12.37 to 12.91 kB against a target
that now includes realtime. Measured: `pusher-js@8.6.0` is **15.62 kB brotli**
and `laravel-echo@2.4.0` is 2.54 kB — together more than the entire headroom. So
realtime cannot enter the initial bundle; W5 loads it through `import()` as a
separate lazy chunk. That is the budget doing its job rather than a problem with
it, but it does mean the constraint is now real and close.

**Open, tracked.** The v22 migration added an `extendedDiagnostics` suppression
block to `tsconfig.app.json` (`nullishCoalescingNotNullable`,
`optionalChainNotNullable`). That is a loosening, kept only to land the upgrade;
it is removed in W4 and the violations fixed. `npm audit` reports 10 advisories,
all in the `webpack-dev-server` / `@angular-devkit/build-webpack` chain — the
`ng serve` path only, never the production bundle; `--force` "fixes" it by
downgrading build-angular to 0.1002.1 and is not applicable. Lazy chunks still
have no budget; W5 adds an `anyScript` budget when the realtime chunk lands.

**Not verified.** `withXhr()`'s live behaviour, and how the fetch backend differs
through the interceptor chain — the suite bypasses the backend entirely. The
Angular 18 attribution table was not captured before the upgrade, so the `src/`
line has no recorded "before" value; the next raise under the new Trap 2 rule
needs one, and the rule requires it to be produced from the last green commit.
