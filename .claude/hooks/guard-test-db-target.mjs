#!/usr/bin/env node
/**
 * PreToolUse / Bash|PowerShell
 * Makes sure a Laravel/Pest test run can never point at the dev database.
 *
 * Resolution order:
 *   1. explicit DB_DATABASE=... in the command itself
 *   2. backend/phpunit.xml (or .dist) env/server override
 *   3. unknown -> ask the user
 */

import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { readInput, isBashLike, getCommand, projectDir, deny, ask, pass } from './_lib.mjs';

const DEV_DB = 'omnihear';

const TEST_RUNNER_RES = [
  /\bphp\s+artisan\s+test\b/i,
  /\bartisan\s+test\b/i,
  /(?:^|[\s/\\"'])(?:\.\/)?vendor\/bin\/pest\b/i,
  /(?:^|[\s/\\"'])(?:\.\/)?vendor\/bin\/phpunit\b/i,
  /(?:^|[\s/\\"'])(?:\.\/)?vendor[\\/]+bin[\\/]+(?:pest|phpunit)\b/i,
  /\bnpx\s+pest\b/i,
];

function isTestRun(command) {
  return TEST_RUNNER_RES.some((re) => re.test(command));
}

/** Pull an inline DB_DATABASE assignment out of the command (bash or PowerShell form). */
function inlineDatabase(command) {
  const res = [
    /(?:^|[\s;&|(])DB_DATABASE\s*=\s*["']?([A-Za-z0-9_.\-]+)/i,
    /\$env:DB_DATABASE\s*=\s*["']?([A-Za-z0-9_.\-]+)/i,
    /--env[= ]\s*["']?DB_DATABASE=([A-Za-z0-9_.\-]+)/i,
  ];
  for (const re of res) {
    const m = re.exec(command);
    if (m && m[1]) return m[1];
  }
  return null;
}

function readPhpunitConfig() {
  const root = projectDir();
  const candidates = [
    join(root, 'backend', 'phpunit.xml'),
    join(root, 'backend', 'phpunit.xml.dist'),
  ];
  for (const file of candidates) {
    try {
      if (existsSync(file)) return { file, xml: readFileSync(file, 'utf8') };
    } catch {
      /* unreadable - treat as missing */
    }
  }
  return null;
}

/** Find <env name="DB_DATABASE" value="..."/> or the <server> equivalent. */
function phpunitDatabase(xml) {
  const re = /<\s*(?:env|server)\b[^>]*\bname\s*=\s*["']DB_DATABASE["'][^>]*>/gi;
  let m;
  while ((m = re.exec(xml)) !== null) {
    const tag = m[0];
    if (/\bvalue\s*=\s*["']([^"']*)["']/i.test(tag)) {
      const value = /\bvalue\s*=\s*["']([^"']*)["']/i.exec(tag)[1].trim();
      if (value) return value;
    }
  }
  return null;
}

const AMBIGUOUS_REASON =
  'Testin hangi veritabanına gideceği belirsiz: komutta DB_DATABASE verilmemiş ve ' +
  'backend/phpunit.xml içinde DB_DATABASE override’ı bulunamadı. ' +
  'Bu durumda test .env dosyasındaki bağlantıyı kullanır ve RefreshDatabase geliştirme veritabanını ' +
  '(omnihear) sıfırlayabilir — integrations.credentials dahil tüm dev verisi silinir. ' +
  'Güvenli kullanım: DB_DATABASE=test_tmp_<sonek> php artisan test';

const DEV_DB_REASON =
  'Test koşumu geliştirme veritabanını (omnihear) hedefliyor. ' +
  'RefreshDatabase şemayı sıfırlar ve entegrasyon credential’ları dahil tüm dev verisi silinir. ' +
  'Bunun yerine omnihear_test veya izole bir DB_DATABASE=test_tmp_<sonek> veritabanı kullan.';

try {
  const input = readInput();
  if (!input || !isBashLike(input)) {
    pass();
  } else {
    const command = getCommand(input);
    if (!isTestRun(command)) {
      pass();
    } else {
      const inline = inlineDatabase(command);
      if (inline) {
        const value = inline.toLowerCase();
        if (value === DEV_DB) deny(DEV_DB_REASON + ' (Komutta DB_DATABASE=' + inline + ' verilmiş.)');
        else pass(); // omnihear_test, test_tmp_* and any other isolated target are fine
      } else {
        const cfg = readPhpunitConfig();
        if (!cfg) {
          ask('backend/phpunit.xml bulunamadı. ' + AMBIGUOUS_REASON);
        } else {
          const configured = phpunitDatabase(cfg.xml);
          if (!configured) {
            ask(cfg.file + ' içinde DB_DATABASE override’ı yok. ' + AMBIGUOUS_REASON);
          } else if (configured.toLowerCase() === DEV_DB) {
            deny(DEV_DB_REASON + ' (' + cfg.file + ' içinde DB_DATABASE="' + configured + '" tanımlı.)');
          } else {
            pass(); // e.g. omnihear_test
          }
        }
      }
    }
  }
} catch {
  pass();
}
process.exit(0);
