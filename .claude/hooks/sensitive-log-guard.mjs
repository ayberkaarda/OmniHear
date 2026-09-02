#!/usr/bin/env node
/**
 * PostToolUse / Edit|Write|MultiEdit
 * Flags log/debug statements that could leak encrypted integration credentials
 * or KVKK-protected feedback PII. Backend PHP and ai-service Python only.
 * Test files are exempt.
 */

import { readInput, isEditLike, getFilePath, getContent, normalizePath, warn, pass } from './_lib.mjs';

const SPEC_NOTE =
  'OMNIHEAR-SPEC §5: integrations.credentials asla loglanmaz. ' +
  '§8 KVKK: feedbacks.body ve feedbacks.author PII’dır. ' +
  'Model’de $hidden tanımlı olsa bile toArray()/json_encode() log’a düşerse değer sızar — ' +
  '$hidden yalnızca serileştirmeyi gizler, ham attribute dizisini değil. ' +
  'Kimliklendirme için ham veri yerine id / correlation_id logla.';

// Call sites need a boundary, not a substring test. Plain `.includes('ray(')`
// matches `toArray(`, `in_array(` and `array(`; `.includes('dd(')` matches `add(`.
// That is not hypothetical: `toArray(Request $request)` is the mandatory signature
// of every Laravel API Resource, so the substring form fired an unavoidable warning
// on every write to those files. The lookbehind excludes word characters and `$`
// only — `$logger->info(...)` must still be caught, so `>` is deliberately absent.
const call = (name) => new RegExp('(?<![\\w$])' + name + '\\s*\\(');

const PHP_LOG_CALLS = [
  { re: /\bLog::/, label: 'Log::' },
  { re: call('logger'), label: 'logger(' },
  { re: call('report'), label: 'report(' },
  { re: call('dd'), label: 'dd(' },
  { re: call('dump'), label: 'dump(' },
  { re: call('var_dump'), label: 'var_dump(' },
  { re: call('ray'), label: 'ray(' },
  { re: call('info'), label: 'info(' },
  { re: call('error_log'), label: 'error_log(' },
];
const PHP_DEBUG_CALLS = [
  { re: call('dd'), label: 'dd(' },
  { re: call('dump'), label: 'dump(' },
  { re: call('var_dump'), label: 'var_dump(' },
  { re: call('ray'), label: 'ray(' },
];

// Sensitive markers matched with word boundaries so `context` does not trip `text`.
const PHP_SENSITIVE = [
  { re: /\bcredentials\b/i, label: 'credentials' },
  { re: /->credentials\b/i, label: '->credentials' },
  { re: /\b2fa_secret\b/i, label: '2fa_secret' },
  { re: /\braw_payload\b/i, label: 'raw_payload' },
  { re: /\bsecret\b/i, label: 'secret' },
  { re: /\bapi_?key\b/i, label: 'api_key' },
  { re: /\bpassword\b/i, label: 'password' },
  { re: /\baccess_token\b/i, label: 'access_token' },
  { re: /\$integration\b/i, label: '$integration' },
];

const PY_LOG_CALLS = [
  { re: /\blogger\./, label: 'logger.' },
  { re: /\blogging\./, label: 'logging.' },
  { re: /(?<![\w.])print\s*\(/, label: 'print(' },
];
const PY_SENSITIVE = [
  { re: /\btext\b/i, label: 'text' },
  { re: /\bbody\b/i, label: 'body' },
  { re: /\bpayload\b/i, label: 'payload' },
  { re: /\bauthor\b/i, label: 'author' },
  { re: /\bemail\b/i, label: 'email' },
  { re: /\bcredentials\b/i, label: 'credentials' },
  { re: /\bapi_?key\b/i, label: 'api_key' },
  { re: /\btoken\b/i, label: 'token', softenBy: /correlation_id|csrf_token|token_count/i },
];

function isTestPath(filePath, pathLower) {
  return (
    /(?:^|\/)tests?\//.test(pathLower) ||
    pathLower.includes('_test.py') ||
    /(?:^|\/)test_[^/]*\.py$/.test(pathLower) ||
    /Test\.php$/.test(filePath) ||
    pathLower.endsWith('/pest.php') ||
    pathLower.endsWith('/conftest.py')
  );
}

function scan(lines, logCalls, sensitive) {
  const findings = [];
  for (const line of lines) {
    const text = line.text;
    const call = logCalls.find((c) => c.re.test(text));
    if (!call) continue;
    for (const marker of sensitive) {
      // Strip known false-positive neighbours before testing this marker.
      const probe = marker.softenBy ? text.replace(new RegExp(marker.softenBy, 'gi'), '') : text;
      if (marker.re.test(probe)) {
        findings.push('satır ' + line.n + ': "' + call.label + '" çağrısı "' + marker.label + '" ile birlikte');
        break;
      }
    }
  }
  return findings;
}

function scanPhpDebug(lines, pathLower) {
  if (!pathLower.includes('app/')) return [];
  const findings = [];
  for (const line of lines) {
    const call = PHP_DEBUG_CALLS.find((c) => c.re.test(line.text));
    if (call) findings.push('satır ' + line.n + ': "' + call.label + '" hata ayıklama çağrısı üretim koduna sızmış');
  }
  return findings;
}

try {
  const input = readInput();
  if (!input || !isEditLike(input)) {
    pass();
  } else {
    const filePath = normalizePath(getFilePath(input));
    const pathLower = filePath.toLowerCase();
    const isPhp = pathLower.includes('backend/') && pathLower.endsWith('.php');
    const isPy = pathLower.includes('ai-service/') && pathLower.endsWith('.py');

    if ((!isPhp && !isPy) || isTestPath(filePath, pathLower)) {
      pass();
    } else {
      const content = getContent(input) ?? '';
      const lines = content.split(/\r?\n/).map((text, i) => ({ n: i + 1, text }));

      let findings = [];
      if (isPhp) {
        findings = findings.concat(scan(lines, PHP_LOG_CALLS, PHP_SENSITIVE));
        for (const f of scanPhpDebug(lines, pathLower)) {
          if (!findings.some((x) => x.split(':')[0] === f.split(':')[0])) findings.push(f);
        }
      } else {
        findings = findings.concat(scan(lines, PY_LOG_CALLS, PY_SENSITIVE));
      }

      if (!findings.length) {
        pass();
      } else {
        warn(
          'Hassas veri loglama riski — dosya: ' + filePath + '\n' +
          findings.map((f) => '- ' + f).join('\n') + '\n\n' + SPEC_NOTE + '\n' +
          'Bu satırları düzelt: hassas alanı log çağrısından çıkar, gerekiyorsa maskele ' +
          '(örn. Str::mask) veya yalnızca id/correlation_id logla. Hata ayıklama çağrılarını (dd/dump/ray) tamamen kaldır.'
        );
      }
    }
  }
} catch {
  pass();
}
process.exit(0);
