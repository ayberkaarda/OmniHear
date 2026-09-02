#!/usr/bin/env node
// Builds frontend/src/styles/tokens.css from frontend/src/styles/tokens.json.
// Run: node scripts/tokens-build.mjs  (or `npm run tokens:build`)

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const TOKENS_PATH = path.join(__dirname, '..', 'src', 'styles', 'tokens.json');
const OUTPUT_PATH = path.join(__dirname, '..', 'src', 'styles', 'tokens.css');

const HEADER = '/* GENERATED from tokens.json by scripts/tokens-build.mjs — do not edit */';

/**
 * Renders tokens.json into CSS text. Pure function so tokens-check.mjs can
 * reuse it to diff against the file on disk without shelling out.
 * @param {object} tokensJson - parsed tokens.json contents
 * @returns {string} generated CSS
 */
export function buildCss(tokensJson) {
  const tokens = tokensJson.tokens;
  const names = Object.keys(tokens).sort((a, b) => a.localeCompare(b));

  for (const name of names) {
    const token = tokens[name];
    if (token.alias !== undefined && !Object.prototype.hasOwnProperty.call(tokens, token.alias)) {
      throw new Error(`tokens.json: "${name}" aliases unknown token "${token.alias}"`);
    }
  }

  const rootLines = [];
  const darkLines = [];

  for (const name of names) {
    const token = tokens[name];
    if (token.alias !== undefined) {
      rootLines.push(`  --${name}: var(--${token.alias});`);
    } else if (token.light !== undefined || token.dark !== undefined) {
      if (token.light === undefined || token.dark === undefined) {
        throw new Error(`tokens.json: "${name}" must define both "light" and "dark"`);
      }
      rootLines.push(`  --${name}: ${token.light};`);
      darkLines.push(`  --${name}: ${token.dark};`);
    } else if (token.value !== undefined) {
      rootLines.push(`  --${name}: ${token.value};`);
    } else {
      throw new Error(`tokens.json: "${name}" has no light/dark, alias, or value`);
    }
  }

  return [
    HEADER,
    '',
    ':root {',
    ...rootLines,
    '}',
    '',
    '.dark {',
    ...darkLines,
    '}',
    ''
  ].join('\n');
}

function main() {
  const raw = readFileSync(TOKENS_PATH, 'utf8');
  const tokensJson = JSON.parse(raw);
  const css = buildCss(tokensJson);
  writeFileSync(OUTPUT_PATH, css, 'utf8');
  console.log(`tokens-build: wrote ${OUTPUT_PATH} (${Object.keys(tokensJson.tokens).length} tokens)`);
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
  main();
}
