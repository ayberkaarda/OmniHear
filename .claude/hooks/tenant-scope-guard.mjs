#!/usr/bin/env node
/**
 * PostToolUse / Edit|Write|MultiEdit
 * Backend PHP only. Feeds tenant isolation regressions back to Claude.
 *
 * Suppression: any line carrying `// tenant-scope: bypass-ok` is excluded from every check.
 */

import { readInput, isEditLike, getFilePath, getContent, normalizePath, warn, pass } from './_lib.mjs';

const BYPASS_MARKER = 'tenant-scope: bypass-ok';

// Tables that legitimately have no company_id.
const TABLE_ALLOWLIST = new Set([
  'companies', 'webhook_events', 'migrations', 'jobs', 'job_batches', 'failed_jobs',
  'personal_access_tokens', 'password_reset_tokens', 'password_resets', 'sessions',
  'cache', 'cache_locks', 'telescope_entries', 'notifications',
]);

const BYPASS_PATTERNS = [
  { needle: 'DB::table(', label: 'DB::table(' },
  { needle: 'withoutGlobalScope(', label: 'withoutGlobalScope(' },
  { needle: '->toBase()', label: '->toBase()' },
  { needle: 'DB::select(', label: 'DB::select(' },
  { needle: 'DB::statement(', label: 'DB::statement(' },
];

function activeLines(content) {
  return content
    .split(/\r?\n/)
    .map((text, i) => ({ n: i + 1, text }))
    .filter((line) => !line.text.includes(BYPASS_MARKER));
}

function checkMigration(pathLower, activeText) {
  if (!pathLower.includes('database/migrations/')) return null;
  if (!activeText.includes('Schema::create(')) return null;
  if (/\bcompany_id\b/.test(activeText)) return null;

  const offenders = [];
  const re = /Schema::create\(\s*['"]([^'"]+)['"]/g;
  let m;
  while ((m = re.exec(activeText)) !== null) {
    const table = m[1].toLowerCase();
    if (!TABLE_ALLOWLIST.has(table)) offenders.push(m[1]);
  }
  if (!offenders.length) return null;

  return (
    'Tenant kapsamı eksik migration — oluşturulan tablo(lar): ' + offenders.join(', ') + '. ' +
    'Bu tablolarda company_id sütunu yok. OmniHear çok kiracılı (multi-tenant) bir SaaS’tır; ' +
    'her iş verisi tablosu company_id (foreignId, constrained, cascadeOnDelete) taşımalı ve ' +
    'sorgular şirket kapsamıyla filtrelenmelidir. company_id olmayan tablo, kiracılar arası veri sızıntısına ' +
    'kapı açar ve sonradan eklenmesi veri taşıma (backfill) gerektirir. ' +
    'Sütunu şimdi ekle; tablo gerçekten global ise ya allowlist’e alınmalı ya da ilgili satıra ' +
    '`// tenant-scope: bypass-ok <gerekçe>` yorumu eklenmelidir.'
  );
}

function checkModel(pathLower, activeText) {
  if (!pathLower.includes('app/models/')) return null;
  if (!/\bcompany_id\b/.test(activeText)) return null;
  if (activeText.includes('BelongsToCompany')) return null;
  if (activeText.includes('CompanyScope')) return null;
  if (activeText.includes('addGlobalScope')) return null;

  return (
    'Tenant kapsamı uygulanmayan model — dosyada company_id geçiyor ama ne BelongsToCompany trait’i, ' +
    'ne CompanyScope, ne de addGlobalScope() var. Global scope olmadan Model::all() / Model::find() ' +
    'başka şirketlerin kayıtlarını döndürür; controller’da filtre unutulduğu anda kiracı izolasyonu kırılır. ' +
    'Modele BelongsToCompany trait’ini ekle (veya booted() içinde addGlobalScope(new CompanyScope) çağır).'
  );
}

function checkScopeBypass(pathLower, lines) {
  if (!pathLower.includes('app/')) return null;
  if (pathLower.includes('database/')) return null;
  if (pathLower.includes('/console/') || pathLower.includes('app/console')) return null;
  if (pathLower.includes('/providers/') || pathLower.includes('app/providers')) return null;

  const hits = [];
  for (const line of lines) {
    for (const pattern of BYPASS_PATTERNS) {
      if (line.text.includes(pattern.needle)) hits.push('satır ' + line.n + ': ' + pattern.label);
    }
  }
  if (!hits.length) return null;

  return (
    'Global scope atlanıyor — ' + hits.join(', ') + '. ' +
    'DB::table(), DB::select(), DB::statement(), ->toBase() ve withoutGlobalScope() Eloquent global scope’unu ' +
    'atlar ve tenant izolasyonunu kırar. KPI ve dashboard agregasyonları tam olarak bu tuzağa düşer: ' +
    'hızlı bir DB::table("feedbacks")->count() tüm şirketlerin verisini sayar. ' +
    'Eloquent modelini kullan (kapsam otomatik uygulanır); ham sorgu gerçekten şartsa sorguya açık bir ' +
    'company_id filtresi ekle ve satıra `// tenant-scope: bypass-ok <gerekçe>` yorumu koy.'
  );
}

try {
  const input = readInput();
  if (!input || !isEditLike(input)) {
    pass();
  } else {
    const filePath = normalizePath(getFilePath(input));
    const pathLower = filePath.toLowerCase();
    if (!pathLower.includes('backend/') || !pathLower.endsWith('.php')) {
      pass();
    } else {
      const content = getContent(input) ?? '';
      const lines = activeLines(content);
      const activeText = lines.map((l) => l.text).join('\n');

      const findings = [
        checkMigration(pathLower, activeText),
        checkModel(pathLower, activeText),
        checkScopeBypass(pathLower, lines),
      ].filter(Boolean);

      if (!findings.length) {
        pass();
      } else {
        const body = findings.map((f, i) => (i + 1) + '. ' + f).join('\n\n');
        warn(
          'Tenant kapsamı denetimi ' + findings.length + ' bulgu üretti — dosya: ' + filePath + '\n\n' + body +
          '\n\nBu bulguları düzelt ve dosyayı yeniden yaz.'
        );
      }
    }
  }
} catch {
  pass();
}
process.exit(0);
