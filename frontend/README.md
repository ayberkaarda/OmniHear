# OmniHear — frontend

Angular 22, standalone components, Signals, zoneless change detection. TailwindCSS
for styling, `@angular/localize` for TR/EN. See `docs/ARCHITECTURE.md` at the repo
root for how this fits with `backend/` and `ai-service/`.

## Requirements

`engines` in `package.json`: Node `>=26.0.0`, npm `>=11.0.0`, and `.nvmrc` at the
repository root carries the same number so CI reads it from one place. That
matters more than it looks: a lockfile is written by whichever npm a developer
runs and validated by whichever npm CI runs, and a lock npm 11 wrote was twice
rejected by npm 10 with `Missing: … from lock file` for packages that were
present locally.

## Install and run

```bash
npm install
npm start          # ng serve, http://localhost:4200
```

## Commands that matter

These are the ones the project's regression gate actually checks — see
`CONTRIBUTING.md` §2 ("Trap 1" and "Trap 2") for why the obvious-looking alternatives
(`tsc --noEmit`, a relaxed `ng build`) do not catch what these do.

| Command | What it checks | Why the naive version is not enough |
|---|---|---|
| `npm run typecheck` | `tsc -p tsconfig.typecheck.json --noEmit` against **every** `src/**/*.ts`, then `tsconfig.e2e.json` for the Playwright suite | `tsconfig.app.json` (what `ng build` type-checks) only reaches files traceable from `src/main.ts`. Anything not yet wired to a route — a shared component, a new service — is silently unchecked and `tsc -p tsconfig.app.json --noEmit` exits 0 on broken code in it. Verified in this tree: a deliberate `const x: number = "string"` in `shared/ui/` was invisible to the app config and caught only by `tsconfig.typecheck.json` as `TS2322`. |
| `npm run build:gate` | Runs `ng build --configuration production` itself (accepts no arguments, so the configuration cannot be swapped) and enforces two budgets: raw initial bundle and brotli transfer size | `ng build`'s own budget is raw-only and can be quietly loosened by editing `angular.json`, moving the `budgets` block to another configuration, or running a non-production build. `build:gate` reads `angular.json` and fails if its threshold disagrees with the script's own constant, so raising one without the other is caught. |
| `npm run i18n:check` | Five rules against `messages.tr.xlf`: source id coverage, no empty `<target>`, no untranslated (English-copied) targets, no orphaned units, and that `messages.xlf`'s trans-unit count matches the source `@@id` count | A green `ng build` says nothing about translation completeness — an empty `<target>` renders as blank text in production, not an error. |
| `npm run tokens:check` | `node scripts/tokens-build.mjs`-derived tokens against WCAG contrast (4.5:1 text, 3:1 large-scale/graphical) and a **calibrated** deuteranopia/protanopia simulation, plus a minimum OKLCH hue-gap check across the seven semantic colors | An uncalibrated color-blindness simulation gives wrong numbers (`docs/LESSONS.md`, 2026-09-02 entry) — the script asserts its own calibration cases first and aborts everything if they fail, rather than trusting an unverified simulator. |

Run, this session, from a clean checkout on this host (`node v26.7.0`, `npm 11.19.0`):

```
$ npm run typecheck
> tsc -p tsconfig.typecheck.json --noEmit && tsc -p tsconfig.e2e.json --noEmit
(exit 0, no output)

$ npm run i18n:check
=== summary ===
i18n:check PASSED
(428/428 trans-units, all five rules OK)

$ npm run tokens:check
== Summary ==
63 pass, 2 warn, 0 fail

$ npm run build:gate
[bundle-check] Initial total — raw 335.75 kB, transfer 94.09 kB
[bundle-check] realtime is lazy — 7 initial script file(s) checked across 2 locale(s), none carries pusher-js or laravel-echo.
[bundle-check] PASS — raw 11.25 kB and transfer 10.91 kB of headroom.
```

The 2 `tokens:check` warnings are pre-existing and below the tool's fail
threshold (`category-praise-fill` vs `category-bug-fill` under deuteranopia
simulation, light and dark, dE just above the 0.10 pass line but flagged as a
warning band) — not something this README changed or is asking you to fix.

Also present but not run as part of this documentation pass: `npm test` (jest),
`npx eslint .`, and `npm run e2e` (Playwright — see `frontend/e2e/` and
`playwright.config.ts`, added alongside this README). `npm run e2e:stack`
brings up the backend-side services the E2E suite needs
(`postgres redis mailpit ai-service backend horizon`) via
`infra/docker-compose.dev.yml`, and `npm run e2e:local` does that plus
`playwright test` in one step.

## Zoneless

The app runs with `provideZonelessChangeDetection()` (stable as of Angular 20;
the polyfills list carries no `zone.js` — confirmed 1.84 kB in the bundle table
above, `zone.js` alone is normally ~36 kB). Re-adding `zone.js` is a conscious
architecture change, not a dependency bump, and is not done without approval
(`CONTRIBUTING.md` Trap 2). It matters when choosing a third-party library: one that
depends on `NgZone` to trigger change detection produces stale UI under this
app — state changes, the view doesn't — because nothing re-renders unless a
signal is read. Zoneless compatibility is a selection criterion for any new
dependency, not an afterthought.

## Bundle budget

Two thresholds, both derived from a measured framework floor rather than
picked or left at the spec's literal "< 250 kB":

- **Raw**, `angular.json` `budgets[type=initial]`: `maximumWarning: 327kb`, `maximumError: 347kb`.
- **Brotli transfer**, `scripts/bundle-check.mjs`: `TRANSFER_MAX_KB = 105`.

`bundle-check.mjs` reads `angular.json`'s `maximumError` back and refuses to run
if it disagrees with its own `RAW_MAX_BYTES` constant, so the two cannot drift
apart silently — the numbers above started at 320kb/100kB (ADR-0007) and moved
to 347kb/105kB when Angular 18 was upgraded to 22 (ADR-0008), each time via a
measured re-derivation, never a bare edit.

Full reasoning, the framework-vs-application attribution table, and the ratchet
rule for ever raising either number: **`docs/adr/0007-initial-bundle-budget-from-measured-floor.md`**
and **`docs/adr/0008-angular-22-and-threshold-rebasing.md`**. Short version:
`@angular/core` + `@angular/router` alone are roughly 80% of the initial
bundle on this tree, lazy loading does not shrink them, and raising the
threshold requires re-measuring the floor and showing the `src/` line did not
grow — not just editing a number.

Realtime (`pusher-js` + `laravel-echo`, `docs/contracts/realtime.md`) is loaded
through a dynamic `import()` after auth resolves specifically because,
together, they are larger than the entire transfer headroom above — see the
`pusher`/`echo` lazy-chunk rows in the `build:gate` output above. `build:gate`
asserts the initial script files carry neither.

## Running it in a container

`docker compose -f infra/docker-compose.dev.yml up -d frontend` serves on :4200.

Two things about that service are deliberate and easy to "fix" wrongly. It runs
`npm ci` **inside** the container and keeps the result in an anonymous volume
that shadows the host `node_modules` — the opposite of the rule the `backend`
service follows for `vendor/`, and for a reason: `node_modules` holds native
binaries compiled for the host OS, so bind-mounting a Windows-built tree into a
Linux image fails at start with `Cannot find native binding`. And the image is
`node:26-alpine`, matching `.nvmrc`; it was left on `node:22` when Node was
pinned everywhere else, which put npm 10 in front of a lockfile npm 11 wrote.

Running `npm start` on the host is still fine and is what the E2E suite does.
