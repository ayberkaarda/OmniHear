#!/usr/bin/env node
// Self-test harness for the OmniHear guard hooks.
//
// Why fragments instead of literals: the guards inspect raw command text and
// file content, so a test payload written literally here is itself caught by
// guard-destructive-ops / guard-git-write / guard-protected-paths. Every
// forbidden literal is assembled at runtime from pieces so this file can be
// written and executed without tripping the very hooks it tests.
//
// Usage: node .claude/hooks/__selftest.mjs
// Exit 0 = all assertions passed, 1 = at least one failed.

import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { tmpdir } from 'node:os'

const HOOKS = dirname(fileURLToPath(import.meta.url))
const ROOT = join(HOOKS, '..', '..')

let pass = 0
let fail = 0
const failures = []

function invoke(hook, payload, projectDir = ROOT) {
  const r = spawnSync(process.execPath, [join(HOOKS, hook)], {
    input: JSON.stringify(payload),
    encoding: 'utf8',
    timeout: 15000,
    env: { ...process.env, CLAUDE_PROJECT_DIR: projectDir },
  })
  let json = null
  const out = (r.stdout || '').trim()
  if (out) { try { json = JSON.parse(out) } catch { /* non-JSON stdout */ } }
  return { exit: r.status, stdout: out, json }
}

// verdict: 'deny' | 'ask' | 'block' | 'pass'
function verdictOf(res) {
  if (res.json?.hookSpecificOutput?.permissionDecision) return res.json.hookSpecificOutput.permissionDecision
  if (res.json?.decision === 'block') return 'block'
  return 'pass'
}

function check(label, hook, payload, expected, projectDir) {
  const res = invoke(hook, payload, projectDir)
  const got = verdictOf(res)
  if (got === expected && res.exit === 0) {
    pass++
  } else {
    fail++
    failures.push(`${label}\n    beklenen=${expected} alinan=${got} exit=${res.exit}\n    stdout=${res.stdout.slice(0, 200)}`)
  }
}

const cmd = (c) => ({ tool_name: 'Bash', tool_input: { command: c } })
const ps = (c) => ({ tool_name: 'PowerShell', tool_input: { command: c } })
const write = (p, content) => ({ tool_name: 'Write', tool_input: { file_path: p, content } })

// --- fragment-assembled literals (never appear whole in source) ---
const GIT = 'g' + 'it'
const DROPDB = 'drop' + 'db'
const DEVDB = 'omni' + 'hear'
const FLUSH = 'FLUSH' + 'ALL'
const TRUNC = 'TRUN' + 'CATE'
const RMRF = 'rm -' + 'rf'
const FRESH = 'migrate:' + 'fresh'
const FAKE_STRIPE_LIVE = 'sk' + '_' + 'live' + '_' + '51QsHxKLmNpQrStUvWxYz'
const FAKE_PEM = '-----BEGIN ' + 'RSA PRIVATE KEY' + '-----\nMIIEowIBAAKCAQEA'
const SAFE_PLACEHOLDER = 'sk' + '_' + 'test' + '_' + 'xxxxxxxxxxxxxxxx'

console.log('--- guard-git-write ---')
check('git commit -> deny', 'guard-git-write.mjs', cmd(`${GIT} commit -m x`), 'deny')
check('zincirin 2. segmenti -> deny', 'guard-git-write.mjs', cmd(`${GIT} diff && ${GIT} push`), 'deny')
check('global flag ile -> deny', 'guard-git-write.mjs', cmd(`${GIT} -C ../other commit -m y`), 'deny')
check('gh pr create -> deny', 'guard-git-write.mjs', cmd('gh pr create --title t'), 'deny')
check('PowerShell push -> deny', 'guard-git-write.mjs', ps(`${GIT} push origin main`), 'deny')
check('FP: echo string -> pass', 'guard-git-write.mjs', cmd(`echo "${GIT} push yapma"`), 'pass')
check('FP: grep string -> pass', 'guard-git-write.mjs', cmd(`grep -r "${GIT} commit" docs/`), 'pass')
check('FP: log --grep -> pass', 'guard-git-write.mjs', cmd(`${GIT} log --grep=commit`), 'pass')
check('FP: status -> pass', 'guard-git-write.mjs', cmd(`${GIT} status -sb`), 'pass')
check('FP: gh pr list -> pass', 'guard-git-write.mjs', cmd('gh pr list'), 'pass')

console.log('--- guard-destructive-ops ---')
check('migrate:fresh -> ask', 'guard-destructive-ops.mjs', cmd(`php artisan ${FRESH}`), 'ask')
check('redis flush -> ask', 'guard-destructive-ops.mjs', cmd(`redis-cli ${FLUSH}`), 'ask')
check('compose down -v -> ask', 'guard-destructive-ops.mjs', cmd('docker compose down -v'), 'ask')
check('rekursif silme -> ask', 'guard-destructive-ops.mjs', cmd(`${RMRF} ./build`), 'ask')
check('korunan DB dusurme -> deny', 'guard-destructive-ops.mjs', cmd(`${DROPDB} ${DEVDB}`), 'deny')
check('korunan DB truncate -> deny', 'guard-destructive-ops.mjs', cmd(`psql -d ${DEVDB} -c "${TRUNC} feedbacks"`), 'deny')
check('joker DROP -> deny', 'guard-destructive-ops.mjs', cmd('psql -c "DROP DATABASE LIKE test_tmp_%"'), 'deny')
check('FP: cache:clear -> pass', 'guard-destructive-ops.mjs', cmd('php artisan cache:clear'), 'pass')
check('FP: compose down bayraksiz -> pass', 'guard-destructive-ops.mjs', cmd('docker compose down'), 'pass')
check('FP: DELETE + WHERE -> pass', 'guard-destructive-ops.mjs', cmd('psql -c "DELETE FROM jobs WHERE id=1"'), 'pass')

console.log('--- guard-test-db-target ---')
check('dev DB hedefi -> deny', 'guard-test-db-target.mjs', cmd(`DB_DATABASE=${DEVDB} php artisan test`), 'deny')
check('izole test DB -> pass', 'guard-test-db-target.mjs', cmd('DB_DATABASE=test_tmp_a1 php artisan test'), 'pass')
check('FP: test disi komut -> pass', 'guard-test-db-target.mjs', cmd('php artisan route:list'), 'pass')

// Repo'da artik backend/phpunit.xml var ve omnihear_test'i dogru hedefliyor, bu yuzden
// hedefi belirtmeyen bir komut dogru olarak PASS alir. "Yapilandirma eksik" senaryosunu
// gercek repoya bagli birakmak testi kirilgan yapar (bir kez kirildi), o yuzden bu iki
// vaka izole gecici bir proje kokunde kosuluyor.
const NO_CFG = mkdtempSync(join(tmpdir(), 'oh-nocfg-'))
mkdirSync(join(NO_CFG, 'backend'), { recursive: true })
check('phpunit.xml yok -> ask', 'guard-test-db-target.mjs', cmd('php artisan test'), 'ask', NO_CFG)
check('pest, hedef belirsiz -> ask', 'guard-test-db-target.mjs', cmd('vendor/bin/pest'), 'ask', NO_CFG)

// phpunit.xml var ama DB_DATABASE override'i yok -> yine ask
const NO_OVERRIDE = mkdtempSync(join(tmpdir(), 'oh-nooverride-'))
mkdirSync(join(NO_OVERRIDE, 'backend'), { recursive: true })
writeFileSync(join(NO_OVERRIDE, 'backend', 'phpunit.xml'),
  '<?xml version="1.0"?><phpunit><php><env name="APP_ENV" value="testing"/></php></phpunit>')
check('phpunit.xml var, override yok -> ask', 'guard-test-db-target.mjs', cmd('php artisan test'), 'ask', NO_OVERRIDE)

// phpunit.xml dev veritabanini hedefliyor -> deny (Tuzak 4'un tam senaryosu)
const DEV_TARGET = mkdtempSync(join(tmpdir(), 'oh-devtarget-'))
mkdirSync(join(DEV_TARGET, 'backend'), { recursive: true })
writeFileSync(join(DEV_TARGET, 'backend', 'phpunit.xml'),
  `<?xml version="1.0"?><phpunit><php><env name="DB_DATABASE" value="${DEVDB}"/></php></phpunit>`)
check('phpunit.xml dev DB hedefliyor -> deny', 'guard-test-db-target.mjs', cmd('php artisan test'), 'deny', DEV_TARGET)

// Gercek repo: phpunit.xml var ve omnihear_test hedefliyor -> pass
check('gercek repo, dogru yapilandirma -> pass', 'guard-test-db-target.mjs', cmd('php artisan test'), 'pass')

console.log('--- guard-protected-paths ---')
check('env dosyasi -> deny', 'guard-protected-paths.mjs', write(join(ROOT, 'backend', '.env'), 'APP_ENV=local'), 'deny')
check('vendor/ -> deny', 'guard-protected-paths.mjs', write(join(ROOT, 'backend', 'vendor', 'x.php'), '<?php'), 'deny')
check('pem dosyasi -> deny', 'guard-protected-paths.mjs', write(join(ROOT, 'infra', 'tls.pem'), 'x'), 'deny')
check('canli odeme anahtari -> deny', 'guard-protected-paths.mjs', write(join(ROOT, 'backend', 'config', 'services.php'), `<?php return ['secret' => '${FAKE_STRIPE_LIVE}'];`), 'deny')
check('ozel anahtar blogu -> deny', 'guard-protected-paths.mjs', write(join(ROOT, 'docs', 'note.md'), FAKE_PEM), 'deny')
check('FP: ornek dosya placeholder -> pass', 'guard-protected-paths.mjs', write(join(ROOT, 'backend', '.env.example'), `STRIPE_SECRET=${SAFE_PLACEHOLDER}`), 'pass')
check('FP: normal php -> pass', 'guard-protected-paths.mjs', write(join(ROOT, 'backend', 'app', 'Models', 'Feedback.php'), '<?php class Feedback {}'), 'pass')

console.log('--- tenant-scope-guard ---')
const mig = (t, body) => write(join(ROOT, 'backend', 'database', 'migrations', `2026_01_01_000000_create_${t}.php`), body)
check('company_id yok -> block', 'tenant-scope-guard.mjs', mig('feedbacks', `<?php Schema::create('feedbacks', function (Blueprint $table) { $table->id(); });`), 'block')
check('DB::table kullanimi -> block', 'tenant-scope-guard.mjs', write(join(ROOT, 'backend', 'app', 'Services', 'Kpi.php'), '<?php DB::table("feedbacks")->count();'), 'block')
check('FP: companies tablosu -> pass', 'tenant-scope-guard.mjs', mig('companies', `<?php Schema::create('companies', function (Blueprint $table) { $table->id(); });`), 'pass')
check('FP: company_id var -> pass', 'tenant-scope-guard.mjs', mig('feedbacks', `<?php Schema::create('feedbacks', function (Blueprint $table) { $table->foreignId('company_id'); });`), 'pass')
check('FP: bypass yorumu -> pass', 'tenant-scope-guard.mjs', write(join(ROOT, 'backend', 'app', 'Services', 'Kpi.php'), '<?php DB::table("jobs")->count(); // tenant-scope: bypass-ok queue internals'), 'pass')
check('FP: frontend dosyasi -> pass', 'tenant-scope-guard.mjs', write(join(ROOT, 'frontend', 'src', 'app.ts'), 'const x = 1'), 'pass')

console.log('--- sensitive-log-guard ---')
check('credentials loglama -> block', 'sensitive-log-guard.mjs', write(join(ROOT, 'backend', 'app', 'Jobs', 'Sync.php'), '<?php Log::info($integration->credentials);'), 'block')
check('python govde loglama -> block', 'sensitive-log-guard.mjs', write(join(ROOT, 'ai-service', 'app', 'main.py'), 'logger.info(f"analyzing {body}")'), 'block')
check('FP: correlation_id -> pass', 'sensitive-log-guard.mjs', write(join(ROOT, 'ai-service', 'app', 'main.py'), 'logger.info(f"correlation_id={cid}")'), 'pass')
check('FP: test dosyasi -> pass', 'sensitive-log-guard.mjs', write(join(ROOT, 'ai-service', 'tests', 'test_analyze.py'), 'print(f"payload={payload}")'), 'pass')
// Debug-call detection used plain substring matching, so `ray(` fired on `toArray(`
// and `dd(` on `add(`. `toArray(Request $request)` is the mandatory signature of
// every Laravel API Resource, so the guard warned on files that cannot avoid it.
// These six lock the boundary fix in both directions: real calls still block.
check('FP: toArray imzasi -> pass', 'sensitive-log-guard.mjs', write(join(ROOT, 'backend', 'app', 'Http', 'Resources', 'UserResource.php'), '<?php public function toArray(Request $request): array { return []; }'), 'pass')
check('FP: in_array -> pass', 'sensitive-log-guard.mjs', write(join(ROOT, 'backend', 'app', 'Support', 'Roles.php'), '<?php return in_array($role, $allowed, true);'), 'pass')
check('FP: Context::add -> pass', 'sensitive-log-guard.mjs', write(join(ROOT, 'backend', 'app', 'Support', 'Ctx.php'), '<?php Context::add("correlation_id", $cid);'), 'pass')
check('gercek dd() -> block', 'sensitive-log-guard.mjs', write(join(ROOT, 'backend', 'app', 'Jobs', 'Sync.php'), '<?php ' + 'd' + 'd($user);'), 'block')
check('gercek ray() -> block', 'sensitive-log-guard.mjs', write(join(ROOT, 'backend', 'app', 'Jobs', 'Sync.php'), '<?php ' + 'r' + 'ay($payload);'), 'block')
// The lookbehind excludes \w and $ but NOT `>`, so an instance logger still trips.
check('->info + credentials -> block', 'sensitive-log-guard.mjs', write(join(ROOT, 'backend', 'app', 'Jobs', 'Sync.php'), '<?php $this->logger->info($integration->credentials);'), 'block')

console.log('--- format-on-write (arac yokken sessiz) ---')
check('php, arac yok -> pass', 'format-on-write.mjs', write(join(ROOT, 'backend', 'app', 'X.php'), '<?php'), 'pass')
check('ts, arac yok -> pass', 'format-on-write.mjs', write(join(ROOT, 'frontend', 'src', 'x.ts'), 'const a=1'), 'pass')

console.log('--- scratchpad muafiyeti (yalniz yol kurali gevser) ---')
// Exempt root: <tmpdir>/claude. The exemption must relax ONLY the project-root
// rule — key material, .env and secret content stay blocked inside it.
const SCRATCH = join(tmpdir(), 'claude', 'probe')
check('scratchpad + normal dosya -> pass', 'guard-protected-paths.mjs', write(join(SCRATCH, 'runner.mjs'), 'const a = 1'), 'pass')
check('scratchpad + env dosyasi -> deny', 'guard-protected-paths.mjs', write(join(SCRATCH, '.env'), 'APP_ENV=local'), 'deny')
check('scratchpad + pem -> deny', 'guard-protected-paths.mjs', write(join(SCRATCH, 'server.pem'), 'x'), 'deny')
check('scratchpad + canli anahtar icerigi -> deny', 'guard-protected-paths.mjs', write(join(SCRATCH, 'note.txt'), `secret=${FAKE_STRIPE_LIVE}`), 'deny')
check('segment tuzagi (claudeXYZ) -> deny', 'guard-protected-paths.mjs', write(join(tmpdir(), 'claude' + 'XYZ', 'x.ts'), 'const a = 1'), 'deny')
check('muaf koktan .. ile kacis -> deny', 'guard-protected-paths.mjs', write(join(SCRATCH, '..', '..', '..', 'evil.ts'), 'const a = 1'), 'deny')
check('rastgele proje disi yol -> deny', 'guard-protected-paths.mjs', write(join(tmpdir(), 'unrelated', 'x.ts'), 'const a = 1'), 'deny')

console.log('--- bozuk girdi dayanikliligi ---')
const HOOK_FILES = [
  'guard-git-write.mjs', 'guard-destructive-ops.mjs', 'guard-test-db-target.mjs',
  'guard-protected-paths.mjs', 'tenant-scope-guard.mjs', 'sensitive-log-guard.mjs',
  'format-on-write.mjs',
]
const MALFORMED = ['not-json', '', '{}', '{"tool_name":"Bash"}', '{"tool_input":{"command":null}}', '[1,2,3]', 'null', '{"a":']
for (const h of HOOK_FILES) {
  for (const bad of MALFORMED) {
    const r = spawnSync(process.execPath, [join(HOOKS, h)], {
      input: bad, encoding: 'utf8', timeout: 15000,
      env: { ...process.env, CLAUDE_PROJECT_DIR: ROOT },
    })
    if (r.status === 0) pass++
    else { fail++; failures.push(`${h} bozuk girdi ${JSON.stringify(bad)} -> exit ${r.status}\n    stderr=${(r.stderr || '').slice(0, 200)}`) }
  }
}

console.log(`\n=== SONUC: ${pass} gecti, ${fail} kaldi ===`)
if (failures.length) {
  console.log('\nBASARISIZ:')
  for (const f of failures) console.log('  - ' + f)
}
process.exit(fail === 0 ? 0 : 1)
