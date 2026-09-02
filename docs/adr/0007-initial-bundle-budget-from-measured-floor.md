# ADR-0007 — Initial bundle budget re-derived from the measured framework floor

- **Status:** Accepted — amended by ADR-0008 (the ratchet clause was unsatisfiable as written)
- **Date:** 2026-09-02
- **Phase:** F2-FE
- **Related spec:** §4 "initial bundle < 250 KB hedefi" · §4 page tree · §0 (spec wins on conflict)
- **Supersedes in part:** ADR-0005's use of 250 kB raw as the operative threshold

## Context

F2-FE built the page tree spec §4 requires — landing, five auth pages, the `/app`
area with nine routes, `/402`, plus four interceptors — with every route lazy
loaded. The production build then failed the budget by 45.36 kB:

```
X [ERROR] bundle initial exceeded maximum budget.
          Budget 256.00 kB was not met by 45.36 kB with a total of 301.36 kB.
```

Nothing was gamed to get there: the `budgets` block was not moved, no
`--localize=false`, no non-production configuration. The number was measured and
brought back for a decision, which is what CLAUDE.md Trap 2 asks for.

The load-bearing measurement is the floor. In the same tree, with `app.routes.ts`
reduced to a single `/health` route and `app.config.ts` / `app.component.ts` at
their F1 state:

| | Initial total (raw) |
|---|---|
| F1 skeleton, one route | **245.00 kB** |
| + landing only (lazy) | 261.37 kB |
| + landing, `routerLink` replaced by `href` | 256.37 kB |
| + auth routes | 271.34 kB |
| + `/app` area | 282.27 kB |
| full application (delivered) | **301.36 kB** raw / **87.63 kB** brotli |

**The skeleton alone is 95.7% of the 256.00 kB threshold.** A single lazy landing
page crosses it. So the threshold is not measuring application code — it is
measuring Angular.

The mechanism is esbuild's module granularity. `@angular/core` and
`@angular/router` are single fesm modules that live in the initial chunk, and
every additional runtime instruction a lazy component uses — host bindings,
`RouterLink`, `RouterLinkActive`, the `@defer` loader — survives tree-shaking and
is added *there*, not to the lazy chunk. Measured: core 142.31 → 151.17 kB,
router 76.95 → 82.90 kB. **Lazy loading does not protect the initial bundle from
framework growth**, which is the assumption the original threshold rested on.

Two further facts constrain the answer. Spec §4 says "hedefi" — a target — while
§2 is titled "Değiştirilemez Kısıtlar"; the "(raw, Angular budget measurement)"
qualifier was CLAUDE.md's addition, not the spec's. And the part of §4 that *is*
a constraint — the page tree — cannot be built under 250 kB raw. Under §0, the
spec wins over CLAUDE.md, so the page tree stands and the threshold is re-derived.

## Decision

Two thresholds, both derived from the measured floor.

1. **Raw**, in `angular.json`: `maximumWarning: 300kb`, `maximumError: 320kb`
   (327 680 bytes). Derivation: 245.00 kB floor + 80 kB allowance for initial
   application code (56 kB today; the remaining spec pages are lazy inside
   `/app`, measured at +10–20 kB per area). Today's 301.36 kB sits 6 kB under the
   warning and 26 kB under the error.
2. **Brotli transfer**, in `frontend/scripts/bundle-check.mjs`:
   `TRANSFER_MAX_KB = 100`. Today: **87.63 kB**.

The gate command becomes `npm run build:gate`. The script runs
`ng build --configuration production` itself and **accepts no arguments**, so the
configuration cannot be swapped. It also reads `angular.json` and fails if the
raw threshold there disagrees with its own constant — the threshold deliberately
lives in two places so that raising it cannot be slipped past a diff.

CLAUDE.md Trap 2 is rewritten with a **ratchet**: the threshold may always fall,
but raising it requires (1) a framework-floor re-measurement showing the floor is
at least 90% of the current threshold — otherwise the overage is application code
and the code gets fixed instead, (2) an ADR with a per-chunk raw delta table
separating `@angular/*` from application chunks, and (3) user approval plus a
PROGRESS deviation row.

## Alternatives considered

- **Transfer size only.** Rejected. The Angular CLI cannot enforce it — there is
  no compression anywhere in `@angular/build/src/utils/bundle-calculator.js`, and
  `angular/angular-cli#22293`, the request for a transfer-size budget, was closed
  as *not planned*. More importantly, brotli hides duplicated application code,
  which is exactly the regression a budget exists to catch. The raw guard has to
  stay.
- **Raw only, raised.** Rejected as insufficient rather than wrong: raw bytes are
  not what reaches a user, and the spec's "< 250 KB" reads naturally as the
  transfer figure the industry quotes. Reporting only raw would keep failing to
  measure the thing the spec cares about.
- **Upgrade Angular to close the gap.** Rejected *as the mechanism*. No primary
  data was found for `@angular/core` / `@angular/router` initial-chunk sizes in
  19–22; the only figures located were third-party marketing posts, internally
  inconsistent, and not used. The measured core+router growth here is 14.8 kB
  against a 45.36 kB gap. Angular 18 is nevertheless out of support and must be
  upgraded — that is a separate decision driven by EOL, not by this budget, and
  the ratchet rule already requires re-measuring the floor afterwards and
  lowering the threshold if it drops.
- **Cut scope.** Rejected. The estimated saving is 10–15 kB, and the things that
  would be cut — `RouterLinkActive`, the interceptors, routed pages — are
  mandated by spec §4. The spec wins.

## Consequences

**Positive.** The gate now measures what users actually download, and reports
both numbers every phase. The two-place raw lock plus the ratchet make a quiet
relaxation harder than it was under the old single-value rule, which had no
procedure at all for a legitimate raise — only a prohibition, which is what
turned an unreachable threshold into a blocked phase. Verified by negative test:
raising `angular.json` alone to `400kb` exits 1.

**Negative.** Two thresholds are two things to maintain, and the parser depends on
the CLI's output table format. If the CLI changes it, the script fails loudly
rather than silently passing — but it still has to be fixed rather than deleted.

**Not covered.** Lazy-chunk growth has no budget (`anyScript` / `bundle` types are
unused); this needs revisiting when a charting library lands. Real LCP/TTI are
not measured at all. Both are gaps the gate does not claim to close.
