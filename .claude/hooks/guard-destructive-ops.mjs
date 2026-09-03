#!/usr/bin/env node
/**
 * PreToolUse / Bash|PowerShell
 * Escalates destructive infrastructure/database commands to the user (ask),
 * and hard-denies the two cases that must never be approved inline:
 *   - wildcard database drops
 *   - anything targeting the protected `omnihear` / `omnihear_test` databases
 */

import { readInput, isBashLike, getCommand, splitSegments, deny, ask, pass } from './_lib.mjs';

const PROTECTED_DB_RE = /\bomnihear(?:_test)?\b/i;
const DROP_DATABASE_RE = /\bdrop\s+database\b/i;
const DROPDB_RE = /(?:^|[\s/\\"'])dropdb\b/i;
const TRUNCATE_RE = /\btruncate\b/i;

/**
 * `dropdb` flags that consume the following word.
 *
 * Without this list the protected-name check reads a connection flag's value as
 * the drop target: on this stack `POSTGRES_USER` is `omnihear`, so the only
 * working invocation is `dropdb -U omnihear --if-exists test_tmp_w8gp`, and
 * scanning the whole command hard-denied it. That made CLAUDE.md section 8's
 * own cleanup procedure impossible to carry out — measured 2026-09-03, after
 * eleven `test_tmp_*` databases had accumulated on the dev server.
 */
const DROPDB_VALUE_FLAGS = /^(?:-h|-p|-U|-d|--host|--port|--username|--maintenance-db)$/i;

/**
 * The database names a drop command actually targets.
 *
 * Returns an empty array when nothing could be parsed, and every caller then
 * falls back to scanning the whole command — fail closed, never open.
 *
 * @returns {string[]}
 */
function dropTargets(command) {
  const targets = [];

  const dropdb = /(?:^|[\s/\\"'(;&|])dropdb\b([^;&|)]*)/gi;
  let m;
  while ((m = dropdb.exec(command)) !== null) {
    const args = (m[1] || '').trim().split(/\s+/).filter(Boolean);
    for (let i = 0; i < args.length; i += 1) {
      const arg = args[i];
      if (arg.startsWith('-')) {
        // `--host=x` carries its value inline and consumes nothing after it.
        if (DROPDB_VALUE_FLAGS.test(arg)) i += 1;
        continue;
      }
      targets.push(arg.replace(/^["'`]|["'`]$/g, ''));
    }
  }

  const sql = /\bdrop\s+database\s+(?:if\s+exists\s+)?["'`]?([A-Za-z0-9_%*$-]+)/gi;
  while ((m = sql.exec(command)) !== null) targets.push(m[1]);

  return targets;
}

/** Hard-deny rules. Order matters: first match wins. */
function hardDeny(command) {
  const wildcardDrop =
    (DROP_DATABASE_RE.test(command) || DROPDB_RE.test(command)) &&
    (/[%*]/.test(command) || /\blike\b/i.test(command));
  if (wildcardDrop) {
    return (
      'Joker desenli veritabanı düşürme yakalandı (DROP DATABASE ile birlikte "%", "*" veya LIKE). ' +
      'Bu desen hangi veritabanlarının silineceğini belirsiz bırakır ve omnihear / omnihear_test dahil ' +
      'her şeyi kapsayabilir. Silinecek veritabanlarını tek tek, açık isimle listele ve kullanıcıdan onay iste.'
    );
  }

  if (!PROTECTED_DB_RE.test(command)) return null;

  if (DROP_DATABASE_RE.test(command) || DROPDB_RE.test(command)) {
    // Judge the parsed target, not the whole command line: a connection flag
    // may legitimately name the protected role. When nothing parses, fall back
    // to the whole command so an unrecognised shape is refused, not allowed.
    const targets = dropTargets(command);
    const scope = targets.length > 0 ? targets.join(' ') : command;
    if (!PROTECTED_DB_RE.test(scope)) return null;

    return (
      'Korunan veritabanı hedefleniyor: "' + (PROTECTED_DB_RE.exec(scope) || [])[0] + '". ' +
      'omnihear (geliştirme) ve omnihear_test (test) veritabanlarının düşürülmesi bu kanaldan yasaktır. ' +
      'omnihear içinde integrations.credentials (şifreli JSONB) ve KVKK kapsamındaki feedbacks kayıtları bulunur; ' +
      'geri dönüşü yoktur. Kullanıcı bu işlemi ayrı ve açık bir talimatla kendisi yapmalıdır.'
    );
  }
  if (TRUNCATE_RE.test(command)) {
    return (
      'Korunan veritabanı üzerinde TRUNCATE yakalandı: "' +
      (PROTECTED_DB_RE.exec(command) || [])[0] +
      '". ' +
      'TRUNCATE geri alınamaz ve feedbacks (KVKK kapsamında PII) ile integrations tablolarını boşaltır. ' +
      'Geçici test verisi için test_tmp_<sonek> veritabanı kullan; ' +
      'korunan veritabanına dokunmak ayrı ve açık bir kullanıcı talimatı gerektirir.'
    );
  }
  return null;
}

/** Detect an `rm` invocation whose combined flags contain both recursive and force. */
function hasRmRecursiveForce(segment) {
  const re = /(?:^|[\s;&|(])rm\b((?:\s+-{1,2}[A-Za-z-]+)*)/gi;
  let m;
  while ((m = re.exec(segment)) !== null) {
    const flags = (m[1] || '').toLowerCase();
    if (/-{1,2}[a-z-]*r/.test(flags) && /-{1,2}[a-z-]*f/.test(flags)) return true;
  }
  return false;
}

function firstMatch(command, re) {
  const m = re.exec(command);
  return m ? m[0] : '';
}

/** ask-level rules: first matching rule wins. */
const ASK_RULES = [
  {
    test: (c) => /\bmigrate:(?:fresh|refresh|reset|rollback)\b/i.test(c),
    reason: (c) =>
      'Yıkıcı migration komutu yakalandı: "' + firstMatch(c, /\bmigrate:(?:fresh|refresh|reset|rollback)\b/i) + '". ' +
      'migrate:fresh/refresh/reset şemayı düşürüp yeniden kurar; hedef veritabanındaki tüm veri ' +
      '(integrations.credentials, feedbacks) kaybolur. migrate:rollback son batch içindeki migration adımlarını geri alır ve veri kaybettirebilir.',
  },
  {
    test: (c) => /\bdb:(?:seed|wipe)\b/i.test(c),
    reason: (c) =>
      'Veritabanı komutu yakalandı: "' + firstMatch(c, /\bdb:(?:seed|wipe)\b/i) + '". ' +
      'db:wipe tüm tabloları düşürür; db:seed mevcut kayıtların üzerine yazabilir ve tenant verisini bozabilir.',
  },
  {
    test: (c) => /\bqueue:(?:flush|forget)\b/i.test(c),
    reason: (c) =>
      'Kuyruk komutu yakalandı: "' + firstMatch(c, /\bqueue:(?:flush|forget)\b/i) + '". ' +
      'Başarısız iş kayıtlarını siler; AnalyzeFeedbackJob için yeniden deneme ve hata teşhisi imkânı kaybolur.',
  },
  {
    test: (c) => /\bhorizon:(?:clear|purge)\b/i.test(c),
    reason: (c) =>
      'Horizon komutu yakalandı: "' + firstMatch(c, /\bhorizon:(?:clear|purge)\b/i) + '". ' +
      'horizon:clear bekleyen kuyruğu siler, horizon:purge çalışan worker süreçlerini sonlandırır; ' +
      'işlenmeyi bekleyen AnalyzeFeedbackJob kayıtları kaybolur ve pending_analysis sayacı gerçeği yansıtmaz olur.',
  },
  {
    test: (c) => /redis-cli/i.test(c) && /\bflush(?:all|db)\b/i.test(c),
    reason: () =>
      'redis-cli FLUSHALL/FLUSHDB yakalandı. Bu komut bekleyen AnalyzeFeedbackJob kuyruğunu ve ' +
      'pending_analysis birikimini siler; ayrıca Horizon metrikleri, önbellek ve rate-limit sayaçları sıfırlanır. ' +
      'Geri dönüşü yoktur.',
  },
  {
    test: (c) => /\bdocker[-\s]+compose\b[\s\S]*\bdown\b/i.test(c) && /(?:^|\s)(?:-v|--volumes)(?:\s|$)/i.test(c),
    reason: () =>
      '"docker compose down -v/--volumes" yakalandı. -v bayrağı named volume’ları da siler: ' +
      'PostgreSQL 16 veri dizini ve Redis kalıcı verisi dahil tüm yerel veritabanı içeriği yok olur.',
  },
  {
    test: (c) => /\bdocker\s+volume\s+(?:rm|prune)\b/i.test(c),
    reason: (c) =>
      'Docker volume komutu yakalandı: "' + firstMatch(c, /\bdocker\s+volume\s+(?:rm|prune)\b/i) + '". ' +
      'PostgreSQL 16 ve Redis verisini tutan volume’lar silinebilir.',
  },
  {
    test: (c) => /\bdocker\s+system\s+prune\b/i.test(c),
    reason: () =>
      '"docker system prune" yakalandı. Kullanılmayan image, container, network ve --volumes ile birlikte ' +
      'veri volume’larını siler; yerel geliştirme ortamı sıfırlanabilir.',
  },
  {
    test: (c) => DROPDB_RE.test(c),
    reason: () => '"dropdb" yakalandı. Hedef veritabanı kalıcı olarak silinir.',
  },
  {
    test: (c) => DROP_DATABASE_RE.test(c),
    reason: () => '"DROP DATABASE" yakalandı. Hedef veritabanı ve içindeki tüm tenant verisi kalıcı olarak silinir.',
  },
  {
    test: (c) => /\bdrop\s+table\b/i.test(c),
    reason: () =>
      '"DROP TABLE" yakalandı. Tablo ve içindeki kayıtlar kalıcı olarak silinir; ' +
      'şema değişikliği migration dışında yapılırsa ortamlar arasında izlenemez hale gelir.',
  },
  {
    test: (c) => /\bdrop\s+schema\b/i.test(c),
    reason: () => '"DROP SCHEMA" yakalandı. Şemadaki tüm tablolar birlikte silinir.',
  },
  {
    test: (c) => TRUNCATE_RE.test(c),
    reason: () =>
      '"TRUNCATE" yakalandı. Tablo içeriği geri alınamaz biçimde boşaltılır; ' +
      'feedbacks (KVKK kapsamında PII) ve integrations gibi tablolarda veri kaybı kalıcıdır.',
  },
  {
    test: (c) => /\bdelete\s+from\b/i.test(c) && !/\bwhere\b/i.test(c),
    reason: () =>
      'WHERE içermeyen "DELETE FROM" yakalandı. Tablodaki tüm satırlar, tüm tenant’lar için silinir. ' +
      'En azından company_id filtresi ekle.',
  },
  {
    test: (c) => hasRmRecursiveForce(c),
    reason: () =>
      '"rm -rf" (recursive + force) yakalandı. Dosya silme yıkıcı bir işlemdir; ' +
      'silinecek yolların tam listesini önce kullanıcıya sun.',
  },
  {
    test: (c) => /\bRemove-Item\b/i.test(c) && /-Recurse\b/i.test(c),
    reason: () =>
      '"Remove-Item -Recurse" yakalandı. Dizin ağacı geri alınamaz biçimde silinir; ' +
      'silinecek yolların tam listesini önce kullanıcıya sun.',
  },
  {
    test: (c) => /\bdel\s+\/s\b/i.test(c) || /\brmdir\s+\/s\b/i.test(c),
    reason: () =>
      'Windows özyinelemeli silme ("del /s" veya "rmdir /s") yakalandı. ' +
      'Silinecek yolların tam listesini önce kullanıcıya sun.',
  },
];

try {
  const input = readInput();
  if (!input || !isBashLike(input)) {
    pass();
  } else {
    const command = getCommand(input);
    const segments = splitSegments(command);
    const haystacks = segments.length ? segments : [command];

    let denyReason = null;
    for (const seg of haystacks) {
      denyReason = hardDeny(seg);
      if (denyReason) break;
    }

    if (denyReason) {
      deny(denyReason);
    } else {
      let askReason = null;
      for (const seg of haystacks) {
        for (const rule of ASK_RULES) {
          if (rule.test(seg)) {
            askReason = rule.reason(seg);
            break;
          }
        }
        if (askReason) break;
      }
      if (askReason) ask(askReason + ' Devam edilsin mi?');
      else pass();
    }
  }
} catch {
  pass();
}
process.exit(0);
