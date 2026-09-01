/**
 * Shared helpers for OmniHear Claude Code guard hooks.
 *
 * Contract:
 *  - every hook reads a single JSON object from stdin
 *  - every hook writes at most one JSON object to stdout
 *  - every hook exits 0, always (fail-open: a crashing guard breaks the session)
 *
 * User facing strings (reasons) are Turkish; code and identifiers are English.
 */

import { readFileSync } from 'node:fs';
import { isAbsolute, resolve } from 'node:path';

/** Read and parse the hook payload from stdin. Returns null on any failure. */
export function readInput() {
  let raw = '';
  try {
    raw = readFileSync(0, 'utf8');
  } catch {
    return null;
  }
  if (!raw || !raw.trim()) return null;
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch {
    return null;
  }
}

function emit(obj) {
  try {
    process.stdout.write(JSON.stringify(obj));
  } catch {
    /* ignore */
  }
}

/** PreToolUse: hard block. */
export function deny(reason) {
  emit({
    hookSpecificOutput: {
      hookEventName: 'PreToolUse',
      permissionDecision: 'deny',
      permissionDecisionReason: String(reason),
    },
  });
}

/** PreToolUse: escalate to the user for explicit approval. */
export function ask(reason) {
  emit({
    hookSpecificOutput: {
      hookEventName: 'PreToolUse',
      permissionDecision: 'ask',
      permissionDecisionReason: String(reason),
    },
  });
}

/** PostToolUse: feed a correction back to Claude (does not stop the user). */
export function warn(reason) {
  emit({ decision: 'block', reason: String(reason) });
}

/** PostToolUse: soft informational context. */
export function context(text) {
  emit({
    hookSpecificOutput: {
      hookEventName: 'PostToolUse',
      additionalContext: String(text),
    },
  });
}

/** No decision: write nothing at all. */
export function pass() {
  /* intentionally silent */
}

/** Project root, used for path containment checks. */
export function projectDir() {
  const raw = process.env.CLAUDE_PROJECT_DIR || process.cwd();
  return normalizePath(resolve(raw));
}

/** Windows-safe path normalization: forward slashes, no trailing slash. */
export function normalizePath(p) {
  if (typeof p !== 'string') return '';
  let out = p.split('\\').join('/').trim();
  out = out.replace(/\/+$/, '');
  return out;
}

/** Absolute, normalized, forward-slashed path for a tool_input.file_path. */
export function absolutePath(p) {
  const n = normalizePath(p);
  if (!n) return '';
  try {
    if (isAbsolute(n) || /^[A-Za-z]:\//.test(n)) return normalizePath(resolve(n));
    return normalizePath(resolve(projectDir(), n));
  } catch {
    return n;
  }
}

export function lower(s) {
  return typeof s === 'string' ? s.toLowerCase() : '';
}

export function toolName(input) {
  return (input && typeof input.tool_name === 'string' && input.tool_name) || '';
}

export function isBashLike(input) {
  const t = toolName(input);
  return t === 'Bash' || t === 'PowerShell';
}

export function isEditLike(input) {
  const t = toolName(input);
  return t === 'Edit' || t === 'Write' || t === 'MultiEdit' || t === 'NotebookEdit';
}

/** The shell command for Bash/PowerShell tools. */
export function getCommand(input) {
  const ti = (input && input.tool_input) || {};
  return typeof ti.command === 'string' ? ti.command : '';
}

/** The target file for Edit/Write/MultiEdit tools. */
export function getFilePath(input) {
  const ti = (input && input.tool_input) || {};
  if (typeof ti.file_path === 'string') return ti.file_path;
  if (typeof ti.path === 'string') return ti.path;
  return '';
}

/**
 * The written content. Write uses `content`, Edit uses `new_string`.
 * MultiEdit carries an `edits` array; concatenate every new_string in it.
 */
export function getContent(input) {
  const ti = (input && input.tool_input) || {};
  const parts = [];
  if (typeof ti.content === 'string') parts.push(ti.content);
  if (typeof ti.new_string === 'string') parts.push(ti.new_string);
  if (Array.isArray(ti.edits)) {
    for (const e of ti.edits) {
      if (e && typeof e.new_string === 'string') parts.push(e.new_string);
      if (e && typeof e.new_source === 'string') parts.push(e.new_source);
    }
  }
  if (typeof ti.new_source === 'string') parts.push(ti.new_source);
  return parts.join('\n');
}

/**
 * Split a shell command into individually inspectable segments.
 * Handles &&, ||, |, ;, &, newlines and line continuations.
 * Quote-aware enough to avoid splitting inside simple quoted strings.
 */
export function splitSegments(command) {
  if (typeof command !== 'string' || !command) return [];
  const BACKSLASH = String.fromCharCode(92);
  const segments = [];
  let current = '';
  let quote = null;
  for (let i = 0; i < command.length; i += 1) {
    const ch = command[i];
    const next = command[i + 1];
    if (quote) {
      current += ch;
      if (ch === quote) quote = null;
      continue;
    }
    if (ch === '"' || ch === "'") {
      quote = ch;
      current += ch;
      continue;
    }
    if (ch === BACKSLASH && next === '\n') {
      i += 1;
      current += ' ';
      continue;
    }
    if (ch === '\n' || ch === ';') {
      segments.push(current);
      current = '';
      continue;
    }
    if (ch === '&' || ch === '|') {
      if (next === ch) i += 1;
      segments.push(current);
      current = '';
      continue;
    }
    current += ch;
  }
  segments.push(current);
  return segments.map((s) => s.trim()).filter((s) => s.length > 0);
}

/**
 * Strip leading noise from a segment so the real executable comes first:
 * env assignments, sudo, command, time, exec wrappers are removed.
 */
export function stripLeading(segment) {
  let s = String(segment || '').trim();
  s = s.replace(/^\(\s*/, '');
  s = s.replace(/^\$\(\s*/, '');
  s = s.replace(/^["'`]+/, '');
  for (;;) {
    const before = s;
    s = s.replace(/^[A-Za-z_][A-Za-z0-9_]*=(?:"[^"]*"|'[^']*'|\S*)\s+/, '');
    s = s.replace(/^(?:sudo|command|time|nohup|env|exec)\s+/i, '');
    if (s === before) break;
  }
  return s.trim();
}

/** Tokenize a command segment, respecting simple quoting. */
export function tokenize(segment) {
  const tokens = [];
  let current = '';
  let quote = null;
  const s = String(segment || '');
  for (let i = 0; i < s.length; i += 1) {
    const ch = s[i];
    if (quote) {
      if (ch === quote) quote = null;
      else current += ch;
      continue;
    }
    if (ch === '"' || ch === "'") {
      quote = ch;
      continue;
    }
    if (/\s/.test(ch)) {
      if (current) tokens.push(current);
      current = '';
      continue;
    }
    current += ch;
  }
  if (current) tokens.push(current);
  return tokens;
}
