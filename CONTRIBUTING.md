# Contributing to OmniHear

## 0. ÖNCELİK SIRASI

1. **`docs/OMNIHEAR-SPEC.md` bağlayıcıdır.** Sonundaki **Errata** bölümü spec'in parçasıdır ve çeliştiği orijinal satırı ezer. Ürün tanımı, teknoloji yığını, veritabanı şeması, veri akışı, kota/paywall akışı ve güvenlik gereksinimleri oradan gelir. Bu dosya ile çelişirse **spec kazanır**.
2. Bu dosya (CONTRIBUTING.md) çalışma biçimini düzenler.

**Başlamadan önce oku:**
- `docs/OMNIHEAR-SPEC.md` — bağlayıcı spec
- `docs/PROGRESS.md` — faz durumu, açık kararlar, bilinen sapmalar
- `docs/LESSONS.md` — **ölçülmüş olgular, append-only.** Buradaki her madde bir hata ayıklama
  oturumuna mal oldu; okumadan başlama, yeniden keşfetme.
- `docs/adr/` — mimari karar kayıtları
- `docs/playbooks/` — tekrar eden işler için uygulama kalıpları (tenant kaynağı, connector,
  webhook, kota/paywall, Angular rota, tokens, AI contract, ADR yazımı)
- `contracts/ai-openapi.json` — Laravel ↔ FastAPI sözleşmesi

Henüz oluşmamış bir dokümana atıf yapma.

---

## 0.1 REPO DÜZENİ — monorepo (KARAR)

```
backend/       Laravel · PHP 8.3 · Sanctum · Horizon+Redis · Reverb · Pest · PostgreSQL 16
frontend/      Angular standalone + Signals · TailwindCSS · @angular/localize (TR/EN)
ai-service/    Python 3.12 · FastAPI · Pydantic v2 · pytest · Ruff
contracts/     OpenAPI şeması + iki tarafın paylaştığı fixture'lar
infra/         docker-compose.dev.yml, K8s manifest'leri
docs/          ADR'ler, playbook'lar, tasarım dokümanları, PROGRESS
```

**File ownership sınırları bu dizinlerle tanımlanır.** Her iş parçası tek bir üst dizinde çalışır; paralel çalışan iki iş aynı dosyaya aynı anda dokunmaz.

---

## 0.2 DEĞİŞMEZ INVARIANT'LAR

Bunlar spec'ten gelir ve her fazda korunur. Her birinin bir testi vardır; test yoksa faz kapanmaz.

| # | Invariant | Nerede zorlanır |
|---|---|---|
| I1 | **Her sorgu tenant-scoped.** `company_id` global scope + policy. `DB::table()` scope'u atlar — gerekçesiz kullanımı yasak. | `backend/tests/Feature/Architecture/TenantScopeArchTest.php` (statik kural) + izolasyon testi (cross-tenant → **404**, 403 değil) |
| I2 | `UNIQUE(integration_id, external_id)` — aynı yorum iki kez analiz edilmez. | migration + connector upsert testi |
| I3 | `UNIQUE(webhook_events.event_id)` — webhook replay/duplicate koruması. | webhook duplicate testi (iş mantığı tam bir kez çalışır) |
| I4 | **Kota sayacı atomik artar.** Dolunca HTTP 402 `{code:"QUOTA_EXCEEDED"}`; yorumlar `pending_analysis` durumunda **birikir, silinmez**. | yarış koşulu testi (limit 3, paralel 5 job → tam 3 artış) |
| I5 | `integrations.credentials` asla loglanmaz/serialize edilmez. `sync_error` mesajı credential içermez. | `backend/tests/Feature/Architecture/SensitiveLogArchTest.php` (statik kural) + `$hidden` + connector hata testi; Python tarafında ruff `T20` (`print` yasağı) |
| I6 | AI servisi **stateless** — metin alır, analiz döner, hiçbir veri saklamaz. | mimari kural; pytest'te kalıcılık çağrısı yok |
| I7 | Laravel ↔ FastAPI: imzalı HMAC header + `correlation_id`, şekil `contracts/` ile doğrulanır. | contract test (iki taraf aynı fixture'ı tüketir) |

I1 ve I5 mimari testler (`backend/tests/Feature/Architecture/`) tarafından statik olarak savunulur: kaynak ağacındaki her tenant-scope kaçışı yazılı bir gerekçe taşımalı, hiçbir credential/PII loglanmamalıdır. `ai-service` tarafında ruff `T20` `print` çağrısını yasaklar — servis yapılandırılmış JSON log yazar.

---

## 1. GIT

- Çalışmayı ana daldan ayrılmış bir dalda yap; küçük, odaklı commit'ler tercih edilir.
- Commit mesajları İngilizce ve açıklayıcıdır (`feat(scope): ...`, `fix(scope): ...`).
- Yıkıcı git/DB işlemleri için §8'e bak.

---

## 2. FAZ KAPILARI

- Her fazın sonunda regresyon kapısı **yeşil** olmalı; çıktılar (sayılar, süreler) kayda geçer.
- "Çalışıyor / test ettim / doğrulandı" iddiası **yalnızca komut + gerçek çıktı** ile kurulur. Çalıştırılmayan test raporlanmaz.
- **Fixture'ın kapsadığı bir şekil için hiçbir test kendi inline JSON'unu kanıt sayamaz.** Fixture konumları: `contracts/fixtures/analyze/`, `backend/tests/Fixtures/webhooks/{stripe,iyzico}/`, `backend/tests/Fixtures/platforms/<platform>/`.
- Testler **ön planda** çalıştırılır; arka plana atılıp sonuç okunmadan rapor yazılmaz.

### Regresyon kapısı — her faz sonunda yeşil olmalı, çıktılar rapora girer

```
cd backend    && php artisan test tests/Feature/Architecture     # I1 & I5 statik kuralları — hızlı-fail
cd backend    && composer validate --strict
cd backend    && composer audit                                # CI-only until 2026-09-02; see below
cd backend    && composer check-platform-reqs                  # CI-only until 2026-09-02; see below
cd backend    && vendor/bin/pint --test
cd backend    && php artisan test --coverage --min=80
cd ai-service && ruff check . && ruff format --check .
cd ai-service && pytest
cd frontend   && npm ci --dry-run                            # lock <-> package.json senkron mu (1.5 sn)
cd frontend   && npm ls chokidar                             # karsilanmamis bagimlilik var mi
cd frontend   && npx eslint .
cd frontend   && npm run typecheck                          # tsconfig.typecheck.json — Tuzak 1'e bak
cd frontend   && npm run build:gate                          # raw + transfer, ikisi de rapora girer
cd frontend   && npx jest
cd frontend   && npm run i18n:check
cd frontend   && npm run tokens:check                          # CI-only until 2026-09-02; see below
contract test (backend Pest + ai-service pytest, contracts/ şemasından)
docker compose -f infra/docker-compose.dev.yml config -q    # compose + Dockerfile referansları geçerli mi
cd frontend   && npx playwright test                        # E2E fazından itibaren
```

Bir bileşen henüz kurulmadıysa o satır atlanır ve **rapora "henüz yok" diye yazılır** — sessizce atlanmaz.

> **Node sürümü `.nvmrc` ile sabit.** Lock dosyasını geliştiricinin npm'i yazar, CI'nınki
> doğrular; ikisi farklı major ise npm 11'in yazdığı bir lock npm 10 tarafından
> "Missing: … from lock file" ile reddedilir — yerelde var olan paketler için, iki kez
> oldu. CI `node-version-file: '.nvmrc'` okur, `frontend/package.json` `engines` ile
> aynı şeyi beyan eder. Node sürümünü değiştiren, ikisini birlikte değiştirir.
>
> **CI ile yerel kapı arasındaki fark.** CI **temiz bir checkout'ta** koşar, yerel kapı
> ise untracked dosyaların bulunduğu çalışma ağacında — yerel yeşil, henüz commit
> edilmemiş bir dosyaya bağımlı olabilir. Faz kapanışında `git status` çıktısı rapora
> girer ki bu fark görünür kalsın. `.github/workflows/ci.yml` ile bu liste arasındaki
> fark CI değiştikçe gözden geçirilir.

> **Tuzak 1 — iki katmanlı, ikisi de sessiz:**
>
> (a) Solution-style kök `tsconfig.json` ile çıplak `tsc --noEmit` hiçbir şey kontrol etmeden 0 döner.
>
> (b) **`tsconfig.app.json` de yetmez.** Angular CLI onu `files: ["src/main.ts"]` + `include: ["src/**/*.d.ts"]` olarak üretir; yani yalnızca **giriş noktasından erişilebilen** dosyalar denetlenir. Henüz bir route'a bağlanmamış her şey — paylaşılan bileşen kütüphanesi, yeni bir servis — sessizce atlanır ve komut 0 döner.
>
> Bu teorik değil: `shared/ui/` altına kasıtlı bir `const x: number = "string"` konuldu ve `tsc -p tsconfig.app.json --noEmit` **exit 0** verdi. Aynı hata `tsconfig.typecheck.json` ile `error TS2322` olarak yakalandı.
>
> **Kapı komutu her zaman `npm run typecheck`** (`tsconfig.typecheck.json`, tüm `src/**/*.ts`, spec'ler hariç). `ng build` kendi config'ini kullanmaya devam eder, bundle çıktısı etkilenmez.
>
> **Tuzak 2:** Initial bundle **iki eşikle** denetlenir: raw (`angular.json` `budgets[type=initial].maximumError`) ve **brotli transfer** (`frontend/scripts/bundle-check.mjs` `TRANSFER_MAX_KB`). Kapı komutu **yalnızca** `npm run build:gate`; çıktıdaki "Initial total" raw **ve** transfer değerleri rapora girer. Angular'ın budget hesaplayıcısı sıkıştırma bilmez (`angular/angular-cli#22293` "not planned"), o yüzden transfer eşiği script'in işidir. Script argüman kabul etmez ve build'i kendisi koşar. Raw eşik iki yerde birden durur ve script eşitliği doğrular; tek taraflı değişiklik **fail** verir *(negatif testi yapıldı: `angular.json`'ı tek başına oynatmak exit 1)*.
>
> Şunlar **hiçbir gerekçeyle** yapılmaz: `budgets` bloğunu taşımak/silmek, `production` dışı configuration, `--localize=false`, error'ı warning'e çevirmek, script'i atlayıp `ng build`'i doğrudan koşmak, chunk hariç tutmak.
>
> Eşik **aşağı** serbestçe iner. **Yukarı** çıkması yalnızca kırmızı bir `build:gate` çıktısından sonra ve **atıf tablosuyla** yapılan bir sınıflandırmayla mümkündür. Tablo initial chunk'ların baytlarını kaynağa göre böler — `@angular/*`, diğer her `node_modules` paketi ayrı, `src/`, styles, polyfills — ve son yeşil commit ile şimdiki hâl için **iki kez** üretilir. Δ = şimdi − son yeşil. Sınıflar birbirini dışlar:
>
> **A — Framework büyümesi.** `@angular/*` sürümü değişti **ve** `src/` satırının Δ'sı ≤ max(1 kB, toplam Δ'nın %5'i). → Her iki eşik `eski eşik + ceil(Δ tam uygulama)` olur. Pay hesabı yok: uygulama değişmediyse uygulamanın **boşluğu** değişmez. Toplam düştüyse eşik aynı Δ kadar **iner**.
>
> **B — Uygulama büyümesi.** `src/` satırı o sınırı aşıyor. → Eşik **değişmez**, kod düzeltilir. Yeni bir lazy route'un initial'daki `@angular/*` satırını büyütmesi (`RouterLinkActive`, `@defer`, form direktifleri) de **B'dir** — framework değil, uygulamanın seçimi.
>
> **C — Araç/kütüphane.** `@angular/*` dışı bir paket belirdi/büyüdü, ya da polyfills büyüdü (zone.js, pusher-js, grafik kütüphanesi). → Eşik **değişmez**; kütüphane lazy chunk'a taşınır ya da çıkarılır (ADR-0005 vakası).
>
> A için üçü birden gerekir: **ADR** (iki atıf tablosu + türetim), **onay**, PROGRESS "Known deviations" satırı; rapor önce kırmızı sonra yeşil `build:gate` çıktısını gösterir.
>
> **Neden eski %90 kuralı kaldırıldı:** eşik `taban + pay` diye türetildiği sürece `taban/eşik = 1 − pay/eşik`; 80/320 ile **%75**, yani kural %90'a hiçbir yolla ulaşamıyordu — ADR-0007 yazıldığı gün zaten %76.6 ile kendi eşiğini reddediyordu. Kastı doğruydu, ölçüsü yanlıştı: taban, framework'ün *talimat başına* şişmesini görmez, o şişme paya yazılır. Ayrıntı: ADR-0008.
>
> **Tuzak 3:** `npm run i18n:check` yeşil olsa bile `messages.tr.xlf` içinde boş `<target>` varsa çeviri eksiktir. Script bunu kontrol etmiyorsa script de güncellenir.
>
> **Tuzak 4:** `php artisan test` hedef veritabanını `RefreshDatabase` ile sıfırlar. `backend/phpunit.xml` içinde `DB_DATABASE` override'ı kurulmadan test koşturmak **dev veritabanını siler**. `phpunit.xml`'deki bu override zorunludur; onsuz test koşturma.

---

## 3. TEST İZOLASYONU

- Aynı anda birden fazla test koşumu yapılacaksa her birine izole veritabanı verilir:
  `DB_DATABASE=test_tmp_<sonek> php artisan test`
  Paylaşılan `omnihear_test` üzerinde eşzamanlı koşum şemaları bozar ve sahte kırmızıya yol açar. Koşum bitince kendi `test_tmp_<sonek>` veritabanını düşür (prosedür §8).

---

## 4. SCOPE VE DİL

- **Scope:** Spec'te olmayan özellik eklenmez. Fikirler ayrı bir öneri olarak not edilir, sessizce eklenmez. Onaylanmış bir karar/kod, açık talimat olmadan değiştirilmez.
- **Dil:** Kod, identifier, commit mesajı, log, dosya adı, doküman içi teknik terimler → **İngilizce**. İnsan iletişimi / rapor → **Türkçe**.
- **UI metni hard-code YASAK.** Angular tarafında `@angular/localize`: her metin `i18n` attribute'lu, `placeholder`/`aria-label`/`title` için `i18n-<attr>`. `messages.tr.xlf` **tam dolu** olmalı — boş `<target>` = kırık faz. Laravel tarafında `lang/{tr,en}` (API hata mesajları, e-posta şablonları).
- **API hataları `{code, message}` döner.** Angular interceptor `code` değerini `$localize` mesajına eşler; `402 QUOTA_EXCEEDED` paywall modalını açar.

---

## 5. YIKICI KOMUT GÜVENLİĞİ

- `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:seed`, `db:wipe`, `queue:flush`, `horizon:clear`, `redis-cli FLUSHALL/FLUSHDB`, `docker compose down -v`, `docker volume/system prune`, `TRUNCATE`, `DROP DATABASE/TABLE/SCHEMA`, WHERE'siz `DELETE FROM`, toplu silme — hiçbiri, kim çalıştırırsa çalıştırsın, **o çağrıya özel açık onay olmadan** çalıştırılamaz.
- **Redis'e özel dikkat:** Horizon kuyruğu Redis'te durur. `FLUSHALL` bekleyen `AnalyzeFeedbackJob`'ları ve `pending_analysis` birikimini siler — kota yükseltmesinden sonra requeue edilecek yorumlar kaybolur.
- Repoda bir script'in var olması onu çalıştırma onayı değildir. Etkisi bilinmiyorsa önce içeriğini oku.
- **Dosya silme/taşıma da yıkıcıdır:** silinecek/taşınacak yolların tam listesi önce açıkça çıkarılır, onaysız uygulanmaz.
- `test_tmp_` önekli veritabanları geçicidir (`RefreshDatabase` şemayı her koşumda kurar) ve faz kapanışında onay istenmeden düşürülebilir; ancak prosedür zorunludur:
  1. `SELECT datname, pid, state FROM pg_stat_activity` ile o veritabanlarına bağlı aktif oturum kalmadığını doğrula,
  2. silinecek isimleri açık liste olarak çıkar ve `omnihear` ile `omnihear_test`'in listede **bulunmadığını** programatik kontrol et,
  3. her birini tek tek, açık isimle düşür: `dropdb --if-exists test_tmp_<sonek>`.

  **Joker desen (`DROP DATABASE test_tmp_%`, `dropdb test_tmp_*` vb.) hiçbir koşulda kullanılmaz.** `omnihear` ve `omnihear_test` bu istisnanın DIŞINDADIR; her zaman açık onay gerektirir.
