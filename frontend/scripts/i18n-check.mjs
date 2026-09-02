#!/usr/bin/env node
// i18n consistency gate for the OmniHear frontend (Angular native i18n, XLIFF 1.2).
//
// WHY RULE 5 EXISTS (read this before touching it):
// In the pass that added the shared/ui design-system components, 16 template
// texts were marked with `i18n="...@@ui.*"` (later found to be 23 once
// counted precisely) while `messages.xlf`/`messages.tr.xlf` still only held
// the 4 trans-units from before that work. Nothing in the regression gate
// caught this: `ng extract-i18n` had in fact been run, but the components
// were not wired into any route, so Angular's compiler never reached their
// templates and silently extracted nothing for them — "Extraction Complete
// (Messages: 4)" with no error. tsc/eslint/jest don't parse i18n attributes
// at all, so the gap was invisible to every other check. Rule 5 below is the
// one check that would have caught it: it counts `@@id` marks directly in
// the source tree (independent of whatever ng extract-i18n produced) and
// compares that count against the trans-units actually present in
// messages.xlf. A mismatch means the source and the translation catalog have
// drifted — most commonly because extract-i18n wasn't (re-)run, but also
// because it structurally can't reach an unrouted component. Either way,
// this script fails loudly instead of staying silent.
//
// Usage: node scripts/i18n-check.mjs

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const ROOT = join(__dirname, '..');
const SRC_XLF = join(ROOT, 'src/locale/messages.xlf');
const TR_XLF = join(ROOT, 'src/locale/messages.tr.xlf');
const SRC_APP_DIR = join(ROOT, 'src/app');

// Rule 3 whitelist: short, non-linguistic tokens that are legitimately
// identical in English and Turkish (brand names, acronyms, technical terms).
// Keep this list explicit and small — anything else identical to its source
// is treated as an untranslated copy.
const IDENTICAL_ALLOWED = new Set(['OmniHear', 'API', 'URL', 'Zendesk', 'Trustpilot', 'ID']);

let failed = false;
const lines = [];

function log(line) {
  lines.push(line);
}

function fail(line) {
  log(`FAIL  ${line}`);
  failed = true;
}

function ok(line) {
  log(`OK    ${line}`);
}

function warn(line) {
  log(`WARN  ${line}`);
}

/** Minimal, dependency-free extraction of <trans-unit id="..."> ... </trans-unit> blocks. */
function parseTransUnits(xmlContent, filePath) {
  const units = new Map();
  const unitRegex = /<trans-unit\s+id="([^"]+)"[^>]*>([\s\S]*?)<\/trans-unit>/g;
  let match;
  while ((match = unitRegex.exec(xmlContent)) !== null) {
    const id = match[1];
    const body = match[2];
    const sourceMatch = /<source>([\s\S]*?)<\/source>/.exec(body);
    const targetMatch = /<target>([\s\S]*?)<\/target>/.exec(body);
    const hasTargetTag = /<target\s*\/>|<target>[\s\S]*?<\/target>/.test(body);
    units.set(id, {
      id,
      source: sourceMatch ? sourceMatch[1] : null,
      target: targetMatch ? targetMatch[1] : hasTargetTag ? '' : null,
      hasTargetTag,
      file: filePath
    });
  }
  return units;
}

function walk(dir, predicate, results = []) {
  for (const entry of readdirSync(dir)) {
    const fullPath = join(dir, entry);
    const stats = statSync(fullPath);
    if (stats.isDirectory()) {
      walk(fullPath, predicate, results);
    } else if (predicate(fullPath)) {
      results.push(fullPath);
    }
  }
  return results;
}

/** Counts every distinct `@@id` mark under src/app/** (html + ts, i18n and i18n-<attr> alike). */
function collectSourceIds() {
  const files = walk(SRC_APP_DIR, (f) => f.endsWith('.html') || f.endsWith('.ts'));
  const ids = new Map(); // id -> [{file, line}]
  const idRegex = /@@([a-zA-Z0-9_.]+)/g;
  for (const file of files) {
    const content = readFileSync(file, 'utf8');
    const relPath = relative(ROOT, file).replace(/\\/g, '/');
    const contentLines = content.split('\n');
    for (let i = 0; i < contentLines.length; i++) {
      idRegex.lastIndex = 0;
      let m;
      while ((m = idRegex.exec(contentLines[i])) !== null) {
        const id = m[1];
        if (!ids.has(id)) {
          ids.set(id, []);
        }
        ids.get(id).push({ file: relPath, line: i + 1 });
      }
    }
  }
  return ids;
}

function isBlankTarget(unit) {
  if (!unit.hasTargetTag) {
    return true; // no <target> element at all
  }
  if (unit.target === null) {
    return true;
  }
  return unit.target.trim().length === 0;
}

function stripPlaceholders(text) {
  // Ignore <x .../> ICU/interpolation placeholders when comparing source vs target text.
  return text.replace(/<x[^>]*\/>/g, '').trim();
}

// ---------------------------------------------------------------------------
log('=== i18n:check ===');

let srcXlfRaw;
let trXlfRaw;
try {
  srcXlfRaw = readFileSync(SRC_XLF, 'utf8');
} catch {
  fail(`Cannot read ${relative(ROOT, SRC_XLF)}`);
}
try {
  trXlfRaw = readFileSync(TR_XLF, 'utf8');
} catch {
  fail(`Cannot read ${relative(ROOT, TR_XLF)}`);
}

if (srcXlfRaw && trXlfRaw) {
  const srcUnits = parseTransUnits(srcXlfRaw, 'messages.xlf');
  const trUnits = parseTransUnits(trXlfRaw, 'messages.tr.xlf');

  // Rule 1: every source id must exist in the tr catalog.
  log('');
  log('-- Rule 1: source -> target id coverage --');
  const missingInTr = [...srcUnits.keys()].filter((id) => !trUnits.has(id));
  if (missingInTr.length === 0) {
    ok(`All ${srcUnits.size} source trans-units have a matching id in messages.tr.xlf.`);
  } else {
    for (const id of missingInTr) {
      fail(`messages.tr.xlf is missing trans-unit id "${id}" (present in messages.xlf).`);
    }
  }

  // Rule 2: no empty targets.
  log('');
  log('-- Rule 2: no empty <target> --');
  const emptyTargets = [...trUnits.values()].filter((u) => isBlankTarget(u));
  if (emptyTargets.length === 0) {
    ok(`No empty <target> elements in messages.tr.xlf (${trUnits.size} units checked).`);
  } else {
    for (const u of emptyTargets) {
      fail(`messages.tr.xlf: trans-unit id "${u.id}" has an empty <target>.`);
    }
  }

  // Rule 3: no untranslated (copy-pasted) targets, aside from the whitelist.
  log('');
  log('-- Rule 3: no untranslated copies (source === target) --');
  let copiedCount = 0;
  for (const u of trUnits.values()) {
    if (u.target === null) {
      continue; // already reported by rule 2
    }
    const srcUnit = srcUnits.get(u.id);
    if (!srcUnit || srcUnit.source === null) {
      continue;
    }
    const sourceText = stripPlaceholders(srcUnit.source);
    const targetText = stripPlaceholders(u.target);
    if (sourceText.length === 0) {
      continue;
    }
    if (sourceText === targetText && !IDENTICAL_ALLOWED.has(sourceText)) {
      copiedCount++;
      fail(`messages.tr.xlf: trans-unit id "${u.id}" target is identical to source ("${sourceText}") — looks untranslated.`);
    }
  }
  if (copiedCount === 0) {
    ok('No untranslated (English-copied) targets found.');
  }

  // Rule 4: no orphaned units in the tr catalog.
  log('');
  log('-- Rule 4: no orphaned tr units --');
  const orphaned = [...trUnits.keys()].filter((id) => !srcUnits.has(id));
  if (orphaned.length === 0) {
    ok('No orphaned trans-units in messages.tr.xlf.');
  } else {
    for (const id of orphaned) {
      fail(`messages.tr.xlf: trans-unit id "${id}" has no matching id in messages.xlf (stale entry?).`);
    }
  }

  // Rule 5: source-tree @@id marks vs messages.xlf trans-unit count.
  log('');
  log('-- Rule 5: source code in sync with messages.xlf (extract-i18n freshness) --');
  const sourceIds = collectSourceIds();
  const sourceIdCount = sourceIds.size;
  const xlfIdCount = srcUnits.size;

  if (sourceIdCount === xlfIdCount) {
    ok(`Source @@id count (${sourceIdCount}) matches messages.xlf trans-unit count (${xlfIdCount}).`);
  } else {
    fail(
      `Source @@id count (${sourceIdCount}) does not match messages.xlf trans-unit count (${xlfIdCount}) — ` +
        `extract-i18n çalıştırılmamış olabilir (source and the XLIFF catalog have drifted).`
    );
    const missingFromXlf = [...sourceIds.keys()].filter((id) => !srcUnits.has(id));
    const extraInXlf = [...srcUnits.keys()].filter((id) => !sourceIds.has(id));
    for (const id of missingFromXlf) {
      const locations = sourceIds.get(id).map((loc) => `${loc.file}:${loc.line}`).join(', ');
      fail(`  @@${id} is marked in source (${locations}) but has no trans-unit in messages.xlf.`);
    }
    for (const id of extraInXlf) {
      fail(`  messages.xlf has trans-unit "${id}" with no corresponding @@id left in the source tree.`);
    }
  }
}

log('');
log('=== summary ===');
log(failed ? 'i18n:check FAILED' : 'i18n:check PASSED');
console.log(lines.join('\n'));

process.exit(failed ? 1 : 0);
