#!/usr/bin/env node
/**
 * PostToolUse / Edit|Write|MultiEdit
 * Formats the single file that was just touched, using whatever toolchain is
 * actually installed. Nothing is installed yet in this repo, so every step is
 * best effort: missing binary, non-zero exit and timeout are all swallowed.
 * This hook never writes to stdout.
 */

import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import { readInput, isEditLike, getFilePath, absolutePath, projectDir } from './_lib.mjs';

const TIMEOUT_MS = 10000;
const IS_WINDOWS = process.platform === 'win32';

/** First existing path among the given candidates, or null. */
function firstExisting(paths) {
  for (const p of paths) {
    try {
      if (p && existsSync(p)) return p;
    } catch {
      /* ignore */
    }
  }
  return null;
}

/**
 * Resolve a binary inside a local toolchain directory, trying Windows suffixes.
 * `dir` is absolute, `name` has no extension.
 */
function resolveBin(dir, name) {
  const candidates = IS_WINDOWS
    ? [join(dir, name + '.cmd'), join(dir, name + '.exe'), join(dir, name + '.bat'), join(dir, name)]
    : [join(dir, name)];
  return firstExisting(candidates);
}

/** Run a resolved binary with shell:false. Silent on every failure. */
function run(bin, args) {
  if (!bin) return;
  try {
    // Windows batch shims cannot be exec'd directly; go through cmd.exe explicitly.
    if (IS_WINDOWS && /\.(cmd|bat)$/i.test(bin)) {
      const comspec = process.env.ComSpec || process.env.COMSPEC || 'C:\\Windows\\System32\\cmd.exe';
      spawnSync(comspec, ['/d', '/s', '/c', bin, ...args], {
        timeout: TIMEOUT_MS,
        shell: false,
        stdio: 'ignore',
        windowsHide: true,
      });
      return;
    }
    spawnSync(bin, args, { timeout: TIMEOUT_MS, shell: false, stdio: 'ignore', windowsHide: true });
  } catch {
    /* formatting is best effort */
  }
}

function extensionOf(pathLower) {
  const dot = pathLower.lastIndexOf('.');
  return dot === -1 ? '' : pathLower.slice(dot);
}

try {
  const input = readInput();
  if (input && isEditLike(input)) {
    const file = absolutePath(getFilePath(input));
    if (file && existsSync(file)) {
      const root = projectDir();
      const lowered = file.toLowerCase();
      const ext = extensionOf(lowered);

      if (ext === '.php') {
        const pint = firstExisting([
          join(root, 'backend', 'vendor', 'bin', 'pint.bat'),
          join(root, 'backend', 'vendor', 'bin', 'pint.exe'),
          join(root, 'backend', 'vendor', 'bin', 'pint'),
        ]);
        run(pint, [file]);
      } else if (['.ts', '.html', '.scss', '.css', '.json'].includes(ext)) {
        // Frontend toolchain only owns files under frontend/.
        if (lowered.includes('/frontend/')) {
          const binDir = join(root, 'frontend', 'node_modules', '.bin');
          run(resolveBin(binDir, 'prettier'), ['--write', file]);
          const eslint = resolveBin(binDir, 'eslint');
          if (eslint) run(eslint, ['--fix', file]);
        }
      } else if (ext === '.py') {
        const ruff = firstExisting([
          join(root, 'ai-service', '.venv', 'Scripts', 'ruff.exe'),
          join(root, 'ai-service', '.venv', 'bin', 'ruff'),
        ]);
        if (ruff) {
          // --unsafe-fixes is deliberately not used: it may change behaviour.
          run(ruff, ['format', file]);
          run(ruff, ['check', '--fix', file]);
        }
      }
    }
  }
} catch {
  /* never let a formatter break the session */
}
process.exit(0);
