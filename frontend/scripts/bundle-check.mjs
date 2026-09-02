#!/usr/bin/env node
/**
 * Initial-bundle gate. Two thresholds, both derived from measurement (ADR-0007).
 *
 * Why a script rather than the Angular budget alone: Angular's budget calculator
 * only ever measures RAW bytes — there is no compression anywhere in
 * @angular/build/src/utils/bundle-calculator.js, and the request for a
 * transfer-size budget (angular/angular-cli#22293) was closed as not planned.
 * But raw size is not what reaches a user; brotli transfer size is. So the raw
 * budget stays as the regression guard that catches duplicated application code
 * (which compresses away and would be invisible in the transfer number), and
 * this script adds the transfer threshold that reflects the spec's actual
 * intent.
 *
 * Why the script runs the build itself instead of parsing someone else's output:
 * so the configuration cannot be swapped. It accepts no arguments. `production`
 * and localization are not negotiable — building with `--localize=false` or a
 * development configuration was one of the documented ways this gate had been
 * gamed before it existed.
 *
 * Why it re-reads angular.json: the raw threshold now lives in two places, and
 * they must agree. Raising it therefore requires editing both, which makes the
 * change impossible to slip past a diff. See CLAUDE.md Trap 2 for the procedure
 * a raise has to follow.
 */

import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

const RAW_MAX_BYTES = 320 * 1024; // 327680 — must equal angular.json's initial.maximumError
const TRANSFER_MAX_KB = 100;

const KB = 1024;

function fail(message) {
  console.error(`\n[bundle-check] FAIL — ${message}`);
  process.exit(1);
}

/** Angular writes budgets as "320kb"/"320kB" (x1024) or "320mb"; normalize to bytes. */
function budgetToBytes(value) {
  const match = /^([\d.]+)\s*(b|kb|mb)$/i.exec(String(value).trim());
  if (!match) return null;
  const size = Number.parseFloat(match[1]);
  const unit = match[2].toLowerCase();
  return unit === 'b' ? size : unit === 'kb' ? size * KB : size * KB * KB;
}

function readConfiguredRawBudget() {
  const angularJson = JSON.parse(readFileSync(join(ROOT, 'angular.json'), 'utf8'));
  const budgets =
    angularJson.projects?.frontend?.architect?.build?.configurations?.production?.budgets;

  if (!Array.isArray(budgets)) {
    fail('angular.json has no production budgets array. The budgets block must not be moved or removed.');
  }

  const initial = budgets.find((budget) => budget.type === 'initial');
  if (!initial) {
    fail('angular.json production budgets has no entry of type "initial".');
  }
  if (!initial.maximumError) {
    fail('The initial budget has no maximumError. An error threshold must not be downgraded to a warning.');
  }

  const bytes = budgetToBytes(initial.maximumError);
  if (bytes === null) {
    fail(`Could not parse initial.maximumError ("${initial.maximumError}").`);
  }
  return bytes;
}

const configuredRaw = readConfiguredRawBudget();
if (configuredRaw !== RAW_MAX_BYTES) {
  fail(
    `angular.json initial.maximumError is ${configuredRaw} bytes but this script expects ${RAW_MAX_BYTES}. ` +
      'The raw threshold lives in both places on purpose — change both, with an ADR, or neither.',
  );
}

console.log(
  `[bundle-check] raw <= ${(RAW_MAX_BYTES / KB).toFixed(2)} kB, transfer <= ${TRANSFER_MAX_KB.toFixed(2)} kB`,
);
console.log('[bundle-check] running: ng build --configuration production\n');

// Invoked through node against the CLI entry point rather than through `npx`:
// `shell: true` would be needed for npx on Windows, and passing an argument
// array through a shell concatenates rather than escapes it (node DEP0190).
// This form needs no shell on any platform.
const build = spawnSync(
  process.execPath,
  [join(ROOT, 'node_modules', '@angular', 'cli', 'bin', 'ng.js'), 'build', '--configuration', 'production'],
  { cwd: ROOT, encoding: 'utf8' },
);

const output = `${build.stdout ?? ''}${build.stderr ?? ''}`;
process.stdout.write(output);

if (build.status !== 0) {
  // The Angular budget itself already failed, or compilation did. Either way the
  // raw threshold has spoken and there is nothing left for this script to add.
  fail(`ng build exited with code ${build.status}.`);
}

// "                      | Initial total             | 301.36 kB |                87.63 kB"
const totals = /\|\s*Initial total\s*\|\s*([\d.]+)\s*(k?B)\s*\|\s*([\d.]+)\s*(k?B)/i.exec(output);
if (!totals) {
  fail(
    'Could not find the "Initial total" row in the build output. If the Angular CLI changed its ' +
      'table format, fix this parser — do not delete the check.',
  );
}

const toKb = (value, unit) => (unit.toLowerCase() === 'kb' ? Number(value) : Number(value) / KB);
const rawKb = toKb(totals[1], totals[2]);
const transferKb = toKb(totals[3], totals[4]);

console.log(`\n[bundle-check] Initial total — raw ${rawKb.toFixed(2)} kB, transfer ${transferKb.toFixed(2)} kB`);

if (transferKb > TRANSFER_MAX_KB) {
  fail(
    `transfer ${transferKb.toFixed(2)} kB exceeds ${TRANSFER_MAX_KB.toFixed(2)} kB by ` +
      `${(transferKb - TRANSFER_MAX_KB).toFixed(2)} kB.`,
  );
}

console.log(
  `[bundle-check] PASS — raw ${(RAW_MAX_BYTES / KB - rawKb).toFixed(2)} kB and ` +
    `transfer ${(TRANSFER_MAX_KB - transferKb).toFixed(2)} kB of headroom.`,
);
