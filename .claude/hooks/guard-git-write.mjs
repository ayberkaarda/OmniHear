#!/usr/bin/env node
/**
 * PreToolUse / Bash|PowerShell
 * Denies every write-capable git (and gh) invocation.
 * The user owns commits, branches and worktrees in this project.
 */

import {
  readInput, isBashLike, getCommand, splitSegments, stripLeading, tokenize, deny, pass,
} from './_lib.mjs';

const FORBIDDEN_GIT = new Set([
  'commit', 'push', 'stash', 'reset', 'checkout', 'switch', 'merge', 'rebase',
  'cherry-pick', 'branch', 'worktree', 'tag', 'clean', 'am', 'apply', 'revert',
  'restore', 'rm', 'mv', 'add',
]);

// Global flags that consume the following token as their value.
const VALUE_FLAGS = new Set([
  '-c', '-C', '--git-dir', '--work-tree', '--namespace', '--exec-path', '--config-env',
]);

// Backup pattern from the hook spec, used as a secondary net when tokenizing
// yields nothing (odd spacing, quoting artefacts).
const FALLBACK_RE = /^\s*git\s+(?:-[^\s]+\s+|--[^\s]+\s+)*(commit|push|stash|reset|checkout|switch|merge|rebase|cherry-pick|branch|worktree|tag|clean|am|apply|revert|restore|rm|mv|add)\b/i;

const GH_FORBIDDEN = [
  { re: /^gh\s+pr\s+create\b/i, what: 'gh pr create' },
  { re: /^gh\s+pr\s+merge\b/i, what: 'gh pr merge' },
  { re: /^gh\s+pr\s+close\b/i, what: 'gh pr close' },
  { re: /^gh\s+release\s+create\b/i, what: 'gh release create' },
  { re: /^gh\s+repo\s+(?:create|delete|fork)\b/i, what: 'gh repo create/delete/fork' },
];

/**
 * Inspect one command segment. Only a segment whose *executable* is git or gh
 * can match, so `echo "git push"` and `grep "git commit"` stay clean.
 */
function inspect(segment) {
  const s = stripLeading(segment);
  const head = /^([^\s]+)/.exec(s);
  if (!head) return null;
  const exe = head[1]
    .split('/').pop()
    .replace(/\.(?:exe|cmd|bat)$/i, '')
    .toLowerCase();

  if (exe === 'gh') {
    for (const entry of GH_FORBIDDEN) {
      if (entry.re.test(s)) return entry.what;
    }
    return null;
  }
  if (exe !== 'git') return null;

  const tokens = tokenize(s);
  for (let i = 1; i < tokens.length; i += 1) {
    const token = tokens[i];
    if (token.startsWith('-')) {
      if (VALUE_FLAGS.has(token) && !token.includes('=')) i += 1;
      continue;
    }
    // First non-flag token is the subcommand; decide on it and stop.
    const sub = token.toLowerCase();
    return FORBIDDEN_GIT.has(sub) ? 'git ' + sub : null;
  }

  const fallback = FALLBACK_RE.exec(s);
  return fallback ? 'git ' + fallback[1].toLowerCase() : null;
}

try {
  const input = readInput();
  if (!input || !isBashLike(input)) {
    pass();
  } else {
    let hit = null;
    for (const segment of splitSegments(getCommand(input))) {
      hit = inspect(segment);
      if (hit) break;
    }
    if (hit) {
      deny(
        'Yasak komut yakalandı: "' + hit + '". ' +
        'Bu projede yazma yapan git komutları yasaktır; kullanıcı commit/branch/worktree işlemlerini kendisi yapar. ' +
        'Serbest olanlar: git status, git diff, git log, git ls-files, git show, git blame, git rev-parse, git config --get. ' +
        'Değişiklikleri çalışma ağacında bırak ve kullanıcıya hangi git işlemini yapması gerektiğini bildir.'
      );
    } else {
      pass();
    }
  }
} catch {
  pass();
}
process.exit(0);
