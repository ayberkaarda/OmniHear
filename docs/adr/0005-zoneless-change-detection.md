# ADR-0005 — Zoneless change detection in the Angular frontend

- **Status:** Accepted
- **Date:** 2026-09-02
- **Phase:** F1
- **Related spec:** §2 "Angular 18+ (standalone components, Signals)" · §4 "initial bundle < 250 KB hedefi"

## Context

The F1 frontend skeleton — one lazy route, one component — built at **261.52 kB** initial
raw, exceeding the 250 kB budget by 5.52 kB. The build failed (exit 1). The budget was not
loosened.

Two cheaper levers were measured first and both failed to matter: dropping `@angular/localize`
saved 0.65 kB, and `HttpClient` with `withFetch()` saved nothing. The `polyfills` chunk was
36.36 kB and consisted entirely of zone.js.

The spec mandates Signals. In a Signals-first application zone.js does no work: change
detection is driven by signal reads, not by monkey-patched `setTimeout`/`Promise`/event
listeners. It was 36 kB of dead weight.

Bundle pressure only grows — F7/F8 add real screens.

## Decision

Run the application zoneless: `provideExperimentalZonelessChangeDetection()`, `zone.js`
removed from `angular.json` polyfills and from `package.json`. Jest switches to
`setupZonelessTestEnv()` from `jest-preset-angular`.

Measured result: initial **226.52 kB** (exit 0), polyfills chunk 36.36 kB → **1.84 kB**,
lazy chunking intact. Verified live in headless Chrome: with no backend serving
`/api/health`, the error branch rendered in the DOM with no manual change detection —
the signal→DOM chain works without zone.js.

`ChangeDetectionStrategy.OnPush` is retained on every component. Zoneless changes *what
triggers* change detection, not *what gets checked*; a non-OnPush component is still
checked unnecessarily when its parent is marked.

## Alternatives considered

- **Accept the overage as a known deviation.** Rejected: `ng build --configuration
  production` would exit 1 on every phase gate, making the gate meaningless.
- **Raise the budget.** Rejected: explicitly forbidden by the working agreement, and it
  hides the problem rather than solving it.
- **Upgrade to Angular 19/20**, where the API is stable. Rejected as disproportionate — a
  framework major upgrade to solve a bundle problem.

## Consequences

**Positive.** ~25 kB of headroom before the budget instead of a 5.52 kB overage. The
architecture is now consistent with the Signals mandate rather than carrying both models.

**Negative.** `provideExperimentalZonelessChangeDetection` is marked experimental in
Angular 18. It stabilised unchanged in 19/20 as `provideZonelessChangeDetection` — a
one-line migration.

`fakeAsync`/`tick` no longer work; tests use real `async`/`await` with
`await fixture.whenStable()` after signal changes.

**Standing constraint.** Zoneless compatibility becomes a selection criterion for every
new third-party library. A library that implicitly depends on `NgZone` produces silently
stale UI — state changes, the view does not — because nothing triggers the scheduler
unless a signal is read. Check for a `zone.js` peer dependency before adopting.
