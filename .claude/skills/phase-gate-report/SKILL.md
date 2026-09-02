---
name: phase-gate-report
description: Faz sonunda regresyon kapısını çalıştırıp FAZ N RAPORU üretmek için kullanılır. Tetikleyiciler faz sonu, faz kapısı, phase gate, regression gate, FAZ N RAPORU, regresyon kapısı, onay iste, faz kapat, "commit et" öncesi doğrulama.
---

# Phase Gate Report

Bu skill, OmniHear monorepo'sunda bir fazı kapatmadan önce **tüm regresyon kapısını sabit sırayla ve ön planda** çalıştırıp, gerçek komut çıktılarına dayalı bir `FAZ N RAPORU` üretir. Hiçbir adım atlanmaz, hiçbir sonuç tahmin edilmez.

## Ne zaman yükle

Kullanıcı "faz bitti", "kapıyı çalıştır", "onaya hazırla", "regresyon" veya "FAZ N RAPORU" dediğinde; ya da bir faz sonu commit talebinden hemen önce.

## Yapılacaklar

1. **Regresyon kapısını sabit sırayla, ön planda çalıştır.** Hiçbir komutu arka plana atma — sonucu göremeden rapora "geçti" yazamazsın.

```bash
cd backend      && vendor/bin/pint --test
cd backend      && php artisan test --coverage --min=80
cd ai-service   && ruff check . && ruff format --check .
cd ai-service   && pytest
cd frontend     && npx eslint .
cd frontend     && npm run typecheck
cd frontend     && npx ng build --configuration production
cd frontend     && npx jest
cd frontend     && npm run i18n:check
# Contract test: backend Pest + ai-service pytest, contracts/ şemasına karşı
cd backend      && vendor/bin/pest --group=contract
cd ai-service   && pytest tests/contract
# E2E fazından itibaren
cd frontend     && npx playwright test
```

2. **Tuzaklara dikkat et:**
   - Tip denetimi iki katmanlı sessizlik barındırır. Çıplak `tsc --noEmit` solution-style kök config'de hiçbir şey denetlemez. **`tsconfig.app.json` de yetmez** — `files: ["src/main.ts"]` olduğu için yalnız giriş noktasından erişilebilen dosyaları görür; route'a bağlanmamış her şey sessizce atlanır ve komut 0 döner (kasıtlı bir tip hatasıyla kanıtlandı). Her zaman **`npm run typecheck`** (`tsconfig.typecheck.json`) kullan.
   - `angular.json` içindeki `budgets.initial.maximumError` değeri gevşetilerek build "yeşile boyanamaz". `ng build --configuration production` çıktısında initial bundle 250KB'ı aşarsa faz **kırmızıdır** — budget dosyasını değiştirmek çözüm değildir.
   - `messages.tr.xlf` içinde boş `<target></target>` bulunması i18n'in eksik olduğu anlamına gelir. Şu komutla tara:
     ```bash
     grep -c "<target/>" frontend/src/locale/messages.tr.xlf
     grep -B2 "<target></target>" frontend/src/locale/messages.tr.xlf
     ```
   - `npm run i18n:check` sıfır olmayan çıkış koduyla biterse faz kırmızıdır; "sonra düzeltiriz" diye rapora yazma.

3. **Her komuttan çıkarılacak sayılar** (rapora ham metin değil, bu sayılar girer):
   - `php artisan test --coverage --min=80` → toplam test sayısı, geçen/kalan, coverage yüzdesi, süre.
   - `pytest` → toplam test, geçen/kalan/skip, süre.
   - `npx jest` → suite sayısı, test sayısı, geçen/kalan.
   - `ng build --configuration production` → initial bundle boyutu (KB), lazy chunk sayısı.
   - `npx playwright test` → senaryo sayısı, geçen/kalan, süre.
   - `pint --test` / `ruff check` / `eslint` → ihlal sayısı (0 olmalı).

4. **`test_tmp_*` veritabanı temizliği** (fazın sonunda, testler bittikten sonra):
   1. `SELECT datname, pid, state FROM pg_stat_activity WHERE datname NOT IN ('omnihear','omnihear_test')` ile o veritabanına bağlı aktif oturum kalmadığını doğrula. (Bu proje PostgreSQL 16 kullanır; MySQL sözdizimi geçerli değildir.)
   2. Silinecek isimleri açık liste olarak çıkar; `omnihear` ve `omnihear_test`'in bu listede **bulunmadığını** programatik kontrol et.
   3. Her birini tek tek, açık isimle düşür: `dropdb --if-exists test_tmp_<sonek>`.
   - **Joker desen (`DROP DATABASE test_tmp_%` veya `dropdb test_tmp_*`) hiçbir koşulda kullanılmaz.**
   - `omnihear` ve `omnihear_test` bu istisnanın dışındadır; onlara dokunmak her zaman açık kullanıcı onayı gerektirir.

5. **Fixture kuralı**'nı doğrula: Değiştirdiğin/eklediğin testler arasında, `contracts/fixtures/analyze/*.json`, `backend/tests/Fixtures/webhooks/{stripe,iyzico}/*.json` veya `backend/tests/Fixtures/platforms/<platform>/*.json` kapsamındaki bir şekil için inline JSON kullanan var mı? Varsa kırmızı işaretle — fixture kanıt sayılmaz demek, inline JSON hiç kanıt sayılmaz demektir.

6. **Sub-agent raporlarını yeniden çalıştır.** Bir sub-agent "testler geçti" dediyse, o iddiayı ana thread'in kendisi komutu tekrar çalıştırarak doğrular. Sub-agent raporu kanıt değildir.

7. **Rapor formatında yaz** ve onay bekle:

```
## FAZ N RAPORU
### Yapılanlar (madde madde, dosya referanslı)
### Dokunulan dosyalar — path | neden
### Çalıştırılan komutlar → gerçek çıktı (sayılar, süreler)
### Kabul kriterleri — [x]/[ ] + gerekçe
### Riskler / açık sorular
### Öneriler (uygulanmadı)
### Onay bekliyorum: F(N+1)
```

8. **DUR.** Onaysız bir sonraki faza geçme. Kullanıcı açıkça onaylamadan yeni faz kapsamına giren dosyalara dokunma.

## Zorunlu testler

Bu skill kendisi test yazmaz — ama çalıştırdığı kapı şu kategorileri **eksiksiz** kapsamalıdır:
- Backend: Pest unit + feature testleri, coverage ≥ %80.
- AI servisi: pytest şema doğrulama + edge-case (dil tespiti, latency regresyonu) testleri.
- Frontend: Jest unit/component testleri + `ng build production` budget doğrulaması.
- Contract: backend ve ai-service'in aynı `contracts/` fixture'ını tükettiğini doğrulayan en az bir test çifti.
- E2E fazından itibaren: Playwright ile en az kritik kullanıcı akışı (login → feedback listesi → paywall).
- i18n: `messages.tr.xlf` boş `<target>` taraması sıfır sonuç vermeli.
