#!/usr/bin/env node
// Validates frontend/src/styles/tokens.json and its generated tokens.css:
//   (a) tokens.css on disk matches a fresh build of tokens.json (byte-identical)
//   (b) WCAG 2.x contrast ratios for text/fill pairs
//   (c) color-blindness distinguishability (Viénot 1999 deuteranopia/protanopia
//       simulation + OKLab dE + OKLCH hue spread)
//   (d) calibration assertions for the color-blindness simulator itself —
//       these run FIRST; if they fail, every other result in this script is
//       treated as untrustworthy and the script aborts immediately. A past
//       run trusted an uncalibrated simulation and shipped a fill-color pair
//       that a deuteranope genuinely cannot tell apart, so the calibration
//       gate exists to catch a broken simulator before it green-lights a bad
//       palette decision again.
//
// Run: node scripts/tokens-check.mjs  (or `npm run tokens:check`)

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { buildCss } from './tokens-build.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const TOKENS_PATH = path.join(__dirname, '..', 'src', 'styles', 'tokens.json');
const CSS_PATH = path.join(__dirname, '..', 'src', 'styles', 'tokens.css');

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

let failCount = 0;
let warnCount = 0;
let passCount = 0;
const lines = [];

function section(title) {
  lines.push('');
  lines.push(`== ${title} ==`);
}

function report(status, message) {
  const tag = status === 'PASS' ? 'PASS' : status === 'WARN' ? 'WARN' : 'FAIL';
  lines.push(`[${tag}] ${message}`);
  if (status === 'PASS') passCount++;
  else if (status === 'WARN') warnCount++;
  else failCount++;
}

function assertCheck(condition, message) {
  report(condition ? 'PASS' : 'FAIL', message);
}

function warnCheck(condition, message) {
  report(condition ? 'PASS' : 'WARN', message);
}

// ---------------------------------------------------------------------------
// Color math — sRGB <-> linear, WCAG relative luminance / contrast
// ---------------------------------------------------------------------------

function hexToRgb01(hex) {
  const m = /^#([0-9a-fA-F]{6})$/.exec(hex.trim());
  if (!m) {
    throw new Error(`Not a 6-digit hex color: "${hex}"`);
  }
  const int = parseInt(m[1], 16);
  return [((int >> 16) & 255) / 255, ((int >> 8) & 255) / 255, (int & 255) / 255];
}

function linearizeChannel(c) {
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function gammaEncodeChannel(c) {
  const clamped = Math.max(0, Math.min(1, c));
  return clamped <= 0.0031308 ? clamped * 12.92 : 1.055 * Math.pow(clamped, 1 / 2.4) - 0.055;
}

function relativeLuminance(hex) {
  const [r, g, b] = hexToRgb01(hex).map(linearizeChannel);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrastRatio(hexA, hexB) {
  const lA = relativeLuminance(hexA);
  const lB = relativeLuminance(hexB);
  const lighter = Math.max(lA, lB);
  const darker = Math.min(lA, lB);
  return (lighter + 0.05) / (darker + 0.05);
}

// ---------------------------------------------------------------------------
// OKLab / OKLCH (Björn Ottosson) — used for perceptual distance + hue spread
// ---------------------------------------------------------------------------

function linearRgb01ToOklab([r, g, b]) {
  const l = 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b;
  const m = 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b;
  const s = 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b;

  const l_ = Math.cbrt(l);
  const m_ = Math.cbrt(m);
  const s_ = Math.cbrt(s);

  return {
    L: 0.2104542553 * l_ + 0.7936177850 * m_ - 0.0040720468 * s_,
    a: 1.9779984951 * l_ - 2.4285922050 * m_ + 0.4505937099 * s_,
    b: 0.0259040371 * l_ + 0.7827717662 * m_ - 0.8086757660 * s_
  };
}

function hexToOklab(hex) {
  const linear = hexToRgb01(hex).map(linearizeChannel);
  return linearRgb01ToOklab(linear);
}

function rgb01ToOklab(rgb01) {
  const linear = rgb01.map(linearizeChannel);
  return linearRgb01ToOklab(linear);
}

function deltaEOk(labA, labB) {
  return Math.sqrt((labA.L - labB.L) ** 2 + (labA.a - labB.a) ** 2 + (labA.b - labB.b) ** 2);
}

function oklchHueDeg(lab) {
  const deg = (Math.atan2(lab.b, lab.a) * 180) / Math.PI;
  return deg < 0 ? deg + 360 : deg;
}

function circularHueDiff(h1, h2) {
  const d = Math.abs(h1 - h2) % 360;
  return d > 180 ? 360 - d : d;
}

// ---------------------------------------------------------------------------
// Color-blindness simulation — Viénot 1999 LMS projection
// (matrices as specified for this design system; NOT Machado 2009/2010)
// ---------------------------------------------------------------------------

const RGB2LMS = [
  [17.8824, 43.5161, 4.11935],
  [3.45565, 27.1554, 3.86714],
  [0.0299566, 0.184309, 1.46709]
];
const LMS2RGB = [
  [0.0809444479, -0.130504409, 0.116721066],
  [-0.0102485335, 0.0540193266, -0.113614708],
  [-0.000365296938, -0.00412161469, 0.693511405]
];
const DEUTAN = [
  [1, 0, 0],
  [0.494207, 0, 1.24827],
  [0, 0, 1]
];
const PROTAN = [
  [0, 2.02344, -2.52581],
  [0, 1, 0],
  [0, 0, 1]
];

function matVec(m, v) {
  return [
    m[0][0] * v[0] + m[0][1] * v[1] + m[0][2] * v[2],
    m[1][0] * v[0] + m[1][1] * v[1] + m[1][2] * v[2],
    m[2][0] * v[0] + m[2][1] * v[1] + m[2][2] * v[2]
  ];
}

/**
 * Simulates how a hex sRGB color appears to a deuteranope/protanope.
 * Pipeline: sRGB -> linear RGB -> LMS -> deficiency projection -> LMS -> linear RGB -> sRGB.
 * Returns sRGB channels as [0,1] floats (NOT yet rounded to 0-255 ints).
 */
function simulateDeficiency(hex, deficiency) {
  const linear = hexToRgb01(hex).map(linearizeChannel);
  const lms = matVec(RGB2LMS, linear);
  const matrix = deficiency === 'deutan' ? DEUTAN : PROTAN;
  const lmsSimulated = matVec(matrix, lms);
  const linearSimulated = matVec(LMS2RGB, lmsSimulated);
  return linearSimulated.map(gammaEncodeChannel);
}

function simulateToOklab(hex, deficiency) {
  return rgb01ToOklab(simulateDeficiency(hex, deficiency));
}

// ---------------------------------------------------------------------------
// (d) CALIBRATION — must run first and must pass, or nothing else is trusted
// ---------------------------------------------------------------------------

function runCalibration() {
  section('(d) Calibration assertions (simulator self-check)');

  const redGreenDeutan = deltaEOk(simulateToOklab('#FF0000', 'deutan'), simulateToOklab('#00FF00', 'deutan'));
  const c1 = redGreenDeutan < 0.25;
  report(c1 ? 'PASS' : 'FAIL', `pure red #FF0000 vs pure green #00FF00 (deutan) collapse: dE=${redGreenDeutan.toFixed(4)} (expect < 0.25)`);

  const blueYellowDeutan = deltaEOk(simulateToOklab('#0000FF', 'deutan'), simulateToOklab('#FFFF00', 'deutan'));
  const c2 = blueYellowDeutan > 0.60;
  report(c2 ? 'PASS' : 'FAIL', `blue #0000FF vs yellow #FFFF00 (deutan) stays distinguishable: dE=${blueYellowDeutan.toFixed(4)} (expect > 0.60)`);

  const blueSimRgb = simulateDeficiency('#0000FF', 'deutan').map((c) => Math.round(c * 255));
  const [br, bg, bb] = blueSimRgb;
  const c3 = bb > br && bb > bg;
  report(
    c3 ? 'PASS' : 'FAIL',
    `blue #0000FF (deutan) simulation stays blue: simulated rgb(${br},${bg},${bb}), b channel dominant: ${c3}`
  );

  const identityDe = deltaEOk(hexToOklab('#3f4a63'), hexToOklab('#3f4a63'));
  const c4 = identityDe === 0;
  report(c4 ? 'PASS' : 'FAIL', `identical color vs itself: dE=${identityDe} (expect exactly 0)`);

  return c1 && c2 && c3 && c4;
}

// ---------------------------------------------------------------------------
// Load tokens
// ---------------------------------------------------------------------------

const tokensJson = JSON.parse(readFileSync(TOKENS_PATH, 'utf8'));
const tokens = tokensJson.tokens;

function resolveColor(name, theme) {
  const token = tokens[name];
  if (!token) {
    throw new Error(`Unknown token "${name}"`);
  }
  if (token.alias !== undefined) {
    return resolveColor(token.alias, theme);
  }
  const value = theme === 'light' ? token.light : token.dark;
  if (typeof value !== 'string' || !value.startsWith('#')) {
    throw new Error(`Token "${name}" (${theme}) is not a hex color: ${value}`);
  }
  return value;
}

// ---------------------------------------------------------------------------
// (a) Production diff
// ---------------------------------------------------------------------------

function runProductionDiff() {
  section('(a) tokens.css production diff');
  const rebuilt = buildCss(tokensJson);
  let onDisk;
  try {
    onDisk = readFileSync(CSS_PATH, 'utf8');
  } catch (err) {
    report('FAIL', `could not read ${CSS_PATH}: ${err.message}`);
    return;
  }
  assertCheck(rebuilt === onDisk, `tokens.css on disk is byte-identical to a fresh build from tokens.json (${CSS_PATH})`);
  if (rebuilt !== onDisk) {
    lines.push(`  on-disk length=${onDisk.length}, rebuilt length=${rebuilt.length}`);
  }
}

// ---------------------------------------------------------------------------
// (b) WCAG contrast
// ---------------------------------------------------------------------------

const SEMANTIC_GROUPS = [
  'sentiment-negative',
  'sentiment-neutral',
  'sentiment-positive',
  'category-complaint',
  'category-praise',
  'category-bug',
  'category-feature-request'
];

const THEMES = ['light', 'dark'];

function runContrastChecks() {
  section('(b) WCAG contrast (sRGB relative luminance)');

  for (const group of SEMANTIC_GROUPS) {
    for (const theme of THEMES) {
      const text = resolveColor(`${group}-text`, theme);
      const bg = resolveColor(`${group}-bg`, theme);
      const ratio = contrastRatio(text, bg);
      assertCheck(ratio >= 4.5, `${group}-text/-bg (${theme}): ${ratio.toFixed(2)}:1 >= 4.5:1`);
    }
  }

  for (const textToken of ['text-primary', 'text-secondary', 'text-muted']) {
    for (const theme of THEMES) {
      const text = resolveColor(textToken, theme);
      const surface = resolveColor('bg-surface', theme);
      const ratio = contrastRatio(text, surface);
      assertCheck(ratio >= 4.5, `${textToken} on bg-surface (${theme}): ${ratio.toFixed(2)}:1 >= 4.5:1`);
    }
  }

  for (const group of SEMANTIC_GROUPS) {
    for (const theme of THEMES) {
      const fill = resolveColor(`${group}-fill`, theme);
      const surface = resolveColor('bg-surface', theme);
      const ratio = contrastRatio(fill, surface);
      assertCheck(ratio >= 3.0, `${group}-fill on bg-surface (${theme}): ${ratio.toFixed(2)}:1 >= 3.0:1`);
    }
  }

  for (const theme of THEMES) {
    const ring = resolveColor('ring-focus', theme);
    const surface = resolveColor('bg-surface', theme);
    const ratio = contrastRatio(ring, surface);
    assertCheck(ratio >= 3.0, `ring-focus on bg-surface (${theme}): ${ratio.toFixed(2)}:1 >= 3.0:1`);
  }
}

// ---------------------------------------------------------------------------
// (c) Color blindness — Viénot 1999 + OKLab dE + OKLCH hue spread
// ---------------------------------------------------------------------------

const CATEGORY_GROUPS = ['category-complaint', 'category-praise', 'category-bug', 'category-feature-request'];

function runColorBlindnessChecks() {
  section("(c) Color-blindness distinguishability (Vienot 1999)");

  // Rule 1: sentiment-negative-fill vs sentiment-positive-fill must stay
  // distinguishable under both deficiencies, in both themes, and must not
  // rely on hue alone (lightness must differ too).
  for (const theme of THEMES) {
    const negHex = resolveColor('sentiment-negative-fill', theme);
    const posHex = resolveColor('sentiment-positive-fill', theme);

    const deDeutan = deltaEOk(simulateToOklab(negHex, 'deutan'), simulateToOklab(posHex, 'deutan'));
    assertCheck(deDeutan >= 0.15, `sentiment-negative-fill vs sentiment-positive-fill (${theme}, deutan): dE=${deDeutan.toFixed(4)} >= 0.15`);

    const deProtan = deltaEOk(simulateToOklab(negHex, 'protan'), simulateToOklab(posHex, 'protan'));
    assertCheck(deProtan >= 0.12, `sentiment-negative-fill vs sentiment-positive-fill (${theme}, protan): dE=${deProtan.toFixed(4)} >= 0.12`);

    const lNeg = hexToOklab(negHex).L;
    const lPos = hexToOklab(posHex).L;
    const dL = Math.abs(lNeg - lPos);
    assertCheck(dL >= 0.15, `sentiment-negative-fill vs sentiment-positive-fill (${theme}) OKLCH lightness gap: dL=${dL.toFixed(4)} >= 0.15`);
  }

  // Rule 2: adjacent sentiment fills (negative/neutral, neutral/positive)
  // must stay distinguishable under deuteranopia.
  const sentimentNeighborPairs = [
    ['sentiment-negative-fill', 'sentiment-neutral-fill'],
    ['sentiment-neutral-fill', 'sentiment-positive-fill']
  ];
  for (const theme of THEMES) {
    for (const [a, b] of sentimentNeighborPairs) {
      const de = deltaEOk(simulateToOklab(resolveColor(a, theme), 'deutan'), simulateToOklab(resolveColor(b, theme), 'deutan'));
      assertCheck(de >= 0.12, `${a} vs ${b} (${theme}, deutan): dE=${de.toFixed(4)} >= 0.12`);
    }
  }

  // Rule 3: every pair of category fills, deuteranopia — WARN (not FAIL) if
  // under threshold, and name the offending pair. Known offender: praise/bug.
  for (const theme of THEMES) {
    for (let i = 0; i < CATEGORY_GROUPS.length; i++) {
      for (let j = i + 1; j < CATEGORY_GROUPS.length; j++) {
        const a = `${CATEGORY_GROUPS[i]}-fill`;
        const b = `${CATEGORY_GROUPS[j]}-fill`;
        const de = deltaEOk(simulateToOklab(resolveColor(a, theme), 'deutan'), simulateToOklab(resolveColor(b, theme), 'deutan'));
        warnCheck(de >= 0.10, `${a} vs ${b} (${theme}, deutan): dE=${de.toFixed(4)} >= 0.10`);
      }
    }
  }

  // Rule 4: hue spread among the seven semantic -fill colors, both themes.
  // Checked as the minimum over ALL pairwise circular hue distances, which
  // for points on a circle is always realized by two hues adjacent in
  // sorted order — so this is equivalent to (and reported as) the minimum
  // adjacent gap once hues are sorted.
  const allFills = [...SEMANTIC_GROUPS.map((g) => `${g}-fill`)];
  for (const theme of THEMES) {
    const hues = allFills
      .map((name) => ({ name, hue: oklchHueDeg(hexToOklab(resolveColor(name, theme))) }))
      .sort((a, b) => a.hue - b.hue);

    let minGap = Infinity;
    let minPair = null;
    for (let i = 0; i < hues.length; i++) {
      const next = hues[(i + 1) % hues.length];
      const gap = circularHueDiff(hues[i].hue, next.hue);
      if (gap < minGap) {
        minGap = gap;
        minPair = [hues[i].name, next.name];
      }
    }
    assertCheck(
      minGap >= 30,
      `seven semantic -fill hues (${theme}): minimum adjacent OKLCH hue gap=${minGap.toFixed(1)}deg (between ${minPair[0]} and ${minPair[1]}) >= 30deg`
    );
    lines.push(`  sorted hues (${theme}): ${hues.map((h) => `${h.name}=${h.hue.toFixed(1)}deg`).join(', ')}`);
  }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main() {
  const calibrationOk = runCalibration();

  if (!calibrationOk) {
    lines.push('');
    lines.push('FATAL: calibration assertions failed. The color-blindness simulator is not');
    lines.push('trustworthy, so (c) results below would be meaningless — aborting before');
    lines.push('running (a), (b), or (c). Fix the simulation pipeline first.');
    console.log(lines.join('\n'));
    process.exit(1);
  }

  runProductionDiff();
  runContrastChecks();
  runColorBlindnessChecks();

  section('Summary');
  lines.push(`${passCount} pass, ${warnCount} warn, ${failCount} fail`);

  console.log(lines.join('\n'));

  if (failCount > 0) {
    process.exit(1);
  }
}

main();
