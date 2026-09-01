#!/usr/bin/env node
/**
 * PreToolUse / Edit|Write|MultiEdit
 * (a) Denies writes to protected paths (secrets, vendored trees, outside the project root).
 * (b) Denies content that carries a real looking credential, with a placeholder allowlist.
 */

import {
  readInput, isEditLike, getFilePath, getContent, absolutePath, normalizePath,
  projectDir, deny, pass,
} from './_lib.mjs';
import { tmpdir, homedir } from 'node:os';
import { join, resolve } from 'node:path';

const SECRET_EXTENSIONS = ['.pem', '.key', '.p12', '.pfx'];
const SECRET_BASENAMES = ['id_rsa', 'id_ed25519', 'id_dsa', 'id_ecdsa'];

const BLOCKED_DIRS = [
  { seg: '/vendor/', label: 'vendor/ (Composer bağımlılıkları)' },
  { seg: '/node_modules/', label: 'node_modules/ (npm bağımlılıkları)' },
  { seg: '/.venv/', label: '.venv/ (Python sanal ortamı)' },
  { seg: '/dist/', label: 'dist/ (derleme çıktısı)' },
  { seg: '/build/', label: 'build/ (derleme çıktısı)' },
  { seg: '/storage/', label: 'storage/ (Laravel çalışma zamanı dizini)' },
];

const ENV_ALLOWED = new Set(['.env.example', '.env.sample']);

/** Drop trailing slashes without touching a bare root. */
function trimSlash(p) {
  let out = p;
  while (out.length > 1 && out.endsWith('/')) out = out.slice(0, -1);
  return out;
}

/**
 * Segment-safe containment test: /tmp/claude contains /tmp/claude/x
 * but NOT /tmp/claudeXYZ. Both sides are already resolved and normalized.
 */
function isUnder(child, parent) {
  if (!child || !parent) return false;
  const c = trimSlash(child.toLowerCase());
  const p = trimSlash(parent.toLowerCase());
  if (!p) return false;
  return c === p || c.startsWith(p + '/');
}

/**
 * Roots that sit outside the project yet are legitimately writable:
 * the harness scratchpad and the per-project memory store. Only the
 * project-root rule is relaxed for these — .env / key-material /
 * secret-content rules still apply inside them.
 *
 * The memory exemption is deliberately narrow. It grants
 * <config>/projects/<slug>/memory and nothing above it, so credential
 * bearing files in the config root (~/.claude.json, keychain caches)
 * stay unwritable.
 */
function exemptRoots() {
  const roots = [];
  const add = (p) => {
    if (!p || !String(p).trim()) return;
    try { roots.push(normalizePath(resolve(String(p).trim()))); } catch { /* ignore */ }
  };

  add(process.env.CLAUDE_SCRATCHPAD_DIR);
  add(join(tmpdir(), 'claude'));

  // Auto-memory: <config>/projects/<project-slug>/memory
  const configDir = process.env.CLAUDE_CONFIG_DIR || join(homedir(), '.claude');
  const memoryOverride = process.env.CLAUDE_MEMORY_DIR;
  if (memoryOverride) {
    add(memoryOverride);
  } else {
    const projectsRoot = normalizePath(resolve(join(configDir, 'projects')));
    // Only a path shaped <projectsRoot>/<slug>/memory qualifies; registered
    // as a marker so isMemoryPath() can check the shape per candidate.
    roots.push({ memoryProjectsRoot: projectsRoot });
  }
  return roots;
}

/** True when abs sits inside <projectsRoot>/<slug>/memory (any depth below). */
function isMemoryPath(abs, projectsRoot) {
  if (!isUnder(abs, projectsRoot)) return false;
  const rest = trimSlash(abs.toLowerCase()).slice(trimSlash(projectsRoot.toLowerCase()).length + 1);
  const parts = rest.split('/');
  return parts.length >= 2 && parts[1] === 'memory';
}

/** True when abs is inside any exempt root. */
function inExemptRoot(abs) {
  for (const r of exemptRoots()) {
    if (typeof r === 'string') {
      if (isUnder(abs, r)) return true;
    } else if (r && r.memoryProjectsRoot) {
      if (isMemoryPath(abs, r.memoryProjectsRoot)) return true;
    }
  }
  return false;
}

/** Reason for a path level block, or null. */
function checkPath(rawPath) {
  const abs = absolutePath(rawPath);
  if (!abs) return null;
  const lowered = abs.toLowerCase();
  const basename = lowered.slice(lowered.lastIndexOf('/') + 1);

  // Outside the project root. The harness scratchpad is exempt from THIS rule
  // only; every other path rule below (.env, key material) still applies there,
  // and so does the content-level secret scan in checkContent().
  const root = projectDir();
  if (!isUnder(abs, root) && !inExemptRoot(abs)) {
    return (
      'Proje kökü dışına yazma engellendi: ' + abs + '. ' +
      'İzin verilen kök: ' + root + ' (ayrıca harness tarafından tahsis edilen scratchpad dizini). ' +
      'Ajanlar yalnızca kendi çalışma dizinlerinin içine veya geçici scratchpad dizinine yazabilir.'
    );
  }

  // .env family, with example/sample exempt.
  if (/^\.env(\..+)?$/.test(basename) && !ENV_ALLOWED.has(basename)) {
    return (
      'Ortam dosyasına yazma engellendi: ' + basename + '. ' +
      '.env dosyaları gerçek credential taşır (APP_KEY, veritabanı şifresi, Stripe/Iyzico webhook secret’ları) ' +
      've asla ajan tarafından düzenlenmez. Yeni bir anahtar tanıtman gerekiyorsa .env.example dosyasına ' +
      'placeholder değerle ekle ve gerçek değeri kullanıcının kendisi girsin.'
    );
  }

  // Vendored / generated / runtime trees.
  const withSlashes = '/' + lowered.replace(/^\/+/, '') + '/';
  for (const dir of BLOCKED_DIRS) {
    if (!withSlashes.includes(dir.seg)) continue;
    // storage/app/public is application owned content, not runtime state.
    if (dir.seg === '/storage/' && withSlashes.includes('/storage/app/public/')) continue;
    return (
      'Korunan dizine yazma engellendi: ' + dir.label + ' — ' + abs + '. ' +
      'Bu ağaçlar paket yöneticisi veya derleme aracı tarafından üretilir; elle yapılan değişiklikler ' +
      'ilk kurulumda/derlemede kaybolur. Değişikliği kaynak dosyada veya bağımlılık tanımında yap.'
    );
  }

  // Key material.
  if (SECRET_EXTENSIONS.some((ext) => basename.endsWith(ext))) {
    return (
      'Anahtar dosyasına yazma engellendi: ' + basename + '. ' +
      'Özel anahtar ve sertifika dosyaları (.pem/.key/.p12/.pfx) repoda tutulmaz ve ajan tarafından üretilmez.'
    );
  }
  if (SECRET_BASENAMES.includes(basename)) {
    return (
      'SSH özel anahtarına yazma engellendi: ' + basename + '. ' +
      'Anahtar materyali repoda tutulmaz ve ajan tarafından üretilmez.'
    );
  }
  return null;
}

const PLACEHOLDER_TOKENS = ['xxxx', 'XXXX', 'REPLACE_ME', 'YOUR_', '<', '...', 'example', 'dummy', 'placeholder', '123456789'];

/** True when the matched value is obviously a placeholder rather than a live secret. */
function isPlaceholder(value) {
  const v = String(value);
  const lowered = v.toLowerCase();
  for (const token of PLACEHOLDER_TOKENS) {
    if (lowered.includes(token.toLowerCase())) return true;
  }
  // 8+ repetitions of a single character, e.g. sk_live_aaaaaaaaaaaaaaaa
  if (/(.)\1{7,}/.test(v)) return true;
  return false;
}

const SECRET_PATTERNS = [
  { re: /sk_live_[A-Za-z0-9]{16,}/g, label: 'Stripe canlı gizli anahtarı (sk_live_…)' },
  { re: /sk_test_[A-Za-z0-9]{16,}/g, label: 'Stripe test gizli anahtarı (sk_test_…)' },
  { re: /rk_live_[A-Za-z0-9]{16,}/g, label: 'Stripe kısıtlı canlı anahtarı (rk_live_…)' },
  { re: /pk_live_[A-Za-z0-9]{16,}/g, label: 'Stripe canlı yayınlanabilir anahtarı (pk_live_…)' },
  { re: /whsec_[A-Za-z0-9]{16,}/g, label: 'Webhook imzalama secret’ı (whsec_…)' },
  { re: /-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/g, label: 'PEM özel anahtar bloğu' },
  { re: /APP_KEY=base64:[A-Za-z0-9+/=]{40,}/g, label: 'Laravel APP_KEY (base64)' },
  { re: /AKIA[0-9A-Z]{16}/g, label: 'AWS erişim anahtarı kimliği (AKIA…)' },
];

/** Reason for a content level block, or null. */
function checkContent(content) {
  if (!content) return null;
  const hits = [];
  for (const pattern of SECRET_PATTERNS) {
    pattern.re.lastIndex = 0;
    let m;
    while ((m = pattern.re.exec(content)) !== null) {
      const value = m[0];
      if (isPlaceholder(value)) continue;
      const preview = value.length > 18 ? value.slice(0, 12) + '…' : value;
      hits.push(pattern.label + ' ("' + preview + '")');
      break;
    }
  }
  if (!hits.length) return null;
  return (
    'Gerçek görünen credential yazılmak isteniyor: ' + hits.join('; ') + '. ' +
    'Secret’lar .env dosyasında tutulur, repoya yazılmaz; bu değer sızdıysa hemen rotate edilmeli. ' +
    'Kod içinde config("services…") üzerinden env’den oku, örnek dosyalara ise ' +
    'REPLACE_ME / YOUR_KEY_HERE gibi placeholder yaz.'
  );
}

try {
  const input = readInput();
  if (!input || !isEditLike(input)) {
    pass();
  } else {
    const filePath = getFilePath(input);
    const pathReason = filePath ? checkPath(filePath) : null;
    if (pathReason) {
      deny(pathReason);
    } else {
      const contentReason = checkContent(getContent(input) ?? '');
      if (contentReason) {
        deny(contentReason + ' Dosya: ' + normalizePath(filePath));
      } else {
        pass();
      }
    }
  }
} catch {
  pass();
}
process.exit(0);
