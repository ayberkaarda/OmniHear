# OmniHear — Kıdemli Mühendislik Seviyesi Geliştirme Promptu

> Bu promptu bir yapay zeka asistanına veya geliştirme ekibine verdiğinde, aşağıdaki spesifikasyona birebir uyulmasını bekle. Belirsiz kalan her nokta için varsayım yapmadan önce soru sorulmalıdır.

---

## 1. Rol ve Bağlam

Sen; ölçeklenebilir SaaS mimarileri, event-driven sistemler ve NLP tabanlı mikroservisler konusunda uzman bir **Kıdemli Full-Stack Mimar / Tech Lead** olarak davranacaksın. Görevin, aşağıda tanımlanan **OmniHear** platformunu üretim kalitesinde (production-grade) tasarlamak ve geliştirmek.

**Ürün Tanımı:** OmniHear, farklı kanallardan (App Store, Google Play, Zendesk, Trustpilot, e-posta, sosyal medya) gelen müşteri geri bildirimlerini tek merkezde toplayan; yapay zeka ile **duygu analizi (sentiment)** ve **kategori sınıflandırması** (şikayet / övgü / bug / özellik talebi) yapan, şirketlere **gerçek zamanlı içgörü** sunan bir **B2B SaaS** platformudur.

---

## 2. Teknoloji Yığını (Değiştirilemez Kısıtlar)

| Katman | Teknoloji | Notlar |
|---|---|---|
| UI/UX | Figma | Design token'ları TailwindCSS config ile senkron tutulacak |
| Frontend | Angular 18+ (standalone components, Signals) + TailwindCSS | NgRx veya Signal Store ile state yönetimi |
| Core Backend | Laravel 11 (PHP 8.3) | API-first, Sanctum ile token auth |
| AI Mikroservisi | Python 3.12 + FastAPI | Pydantic v2 şema doğrulama, async endpoint'ler |
| Gerçek Zamanlı | Laravel Reverb (fallback: Pusher) | Private channel + auth guard |
| Kuyruk | Laravel Horizon + Redis | Tüm analiz işleri asenkron job olarak |
| Veritabanı | PostgreSQL 16 (tercih) | JSONB kolonlar AI çıktıları için |
| Cache | Redis | KPI agregasyonları için |
| Ödeme | Stripe (global) + Iyzico (TR) | Webhook imza doğrulaması zorunlu |
| Konteyner | Docker + docker-compose (dev), K8s-ready imajlar (prod) | |

---

## 3. Mimari Prensipler

1. **Servis ayrımı:** Laravel iş mantığı ve veri sahipliğini tutar; Python servisi **stateless**'tır — metin alır, analiz döner, hiçbir veri saklamaz.
2. **Asenkron öncelikli:** Yorum çekme (ingestion) ve AI analizi asla HTTP request yaşam döngüsünde çalışmaz; tümü kuyruk job'larıdır.
3. **Idempotency:** Aynı yorumun iki kez analiz edilmesini önlemek için her feedback'e `external_id + platform` unique constraint'i ve job'lara idempotency key uygulanır.
4. **Multi-tenancy:** Tüm tablolar `tenant_id (company_id)` ile izole edilir; global scope + policy'lerle veri sızıntısı engellenir.
5. **Dayanıklılık:** Python servisi düşerse Laravel job'ları exponential backoff ile retry eder (max 5 deneme), sonra dead-letter kuyruğuna düşer.
6. **Gözlemlenebilirlik:** Structured logging (JSON), correlation ID her istekte iki servis arasında taşınır; Sentry (hata) + Prometheus/Grafana (metrik).

---

## 4. Frontend Mimarisi (Angular)

### Sayfa Ağacı
```
/                      → Landing (Hero, Özellikler, Entegrasyonlar, Fiyatlandırma, SSS, CTA)
/auth
  /login  /register  /forgot-password  /reset-password  /verify-email
/app (AuthGuard + SubscriptionGuard)
  /overview            → KPI kartları, trend grafikleri (duygu skoru zaman serisi)
  /inbox               → Sanal scroll'lu feedback tablosu; AI etiketi, kaynak, duygu, tarih filtreleri
  /inbox/:id           → Yorum detayı + AI analiz gerekçesi
  /integrations        → Bağlantı kartları (OAuth veya API key), bağlantı sağlık durumu
  /settings
    /profile  /team  /billing  /api-keys  /notifications
/402                   → Kota doldu / paywall ekranı
```

### Teknik Gereksinimler
- **Lazy loading:** Her modül route bazlı lazy yüklenir; initial bundle < 250 KB hedefi.
- **Reaktif akış:** WebSocket event'leri (`FeedbackAnalyzed`) Signal Store'a akar; Inbox listesi sayfa yenilemeden güncellenir.
- **Interceptor'lar:** JWT ekleme, 401 → login yönlendirme, **402 → paywall modalı** tetikleme, global hata bildirimi (toast).
- **Erişilebilirlik:** WCAG 2.1 AA; klavye navigasyonu ve aria etiketleri zorunlu.
- **i18n:** TR/EN çift dil desteği baştan kurgulanır (`@angular/localize`).

---

## 5. Veritabanı Şeması (PostgreSQL)

```
companies        id, name, plan, analyzed_feedback_count, quota_limit, created_at
users            id, company_id (FK), name, email (unique), password, role (owner/admin/member),
                 email_verified_at, 2fa_secret, last_login_ip
subscriptions    id, company_id, provider (stripe/iyzico), provider_subscription_id,
                 plan, status, current_period_start/end, canceled_at
integrations     id, company_id, platform (enum), credentials (encrypted JSONB),
                 status (active/error/paused), last_synced_at, sync_error
feedbacks        id, company_id, integration_id, external_id, author, body (text),
                 source_url, published_at, raw_payload (JSONB)
                 UNIQUE(integration_id, external_id)
ai_analyses      id, feedback_id (FK, unique), sentiment_score (numeric -1..1),
                 sentiment_label (positive/neutral/negative), category (enum),
                 confidence, keywords (JSONB), model_version, analyzed_at
webhook_events   id, provider, event_id (unique), payload (JSONB), processed_at
audit_logs       id, company_id, user_id, action, subject_type/id, ip, created_at
```

**Kritik notlar:**
- `integrations.credentials` Laravel `encrypted` cast ile şifrelenir; asla log'lanmaz.
- `ai_analyses.model_version` tutulur ki model güncellenince eski analizler yeniden işlenebilsin.
- Yüksek hacim için `feedbacks` tablosuna `published_at` üzerinden aylık partitioning planlanır.

---

## 6. Sistem Veri Akışı

1. **Ingestion (Cron/Scheduler):** Laravel Scheduler her 5 dakikada aktif entegrasyonlar için `FetchFeedbackJob` dispatch eder. Platform API'lerinden **cursor/timestamp bazlı** artımlı çekim yapılır (tam tarama yasak). Rate limit'lere platform bazlı throttle uygulanır.
2. **Analiz İsteği:** Yeni feedback kaydedilince `AnalyzeFeedbackJob` kuyruğa girer → FastAPI `/v1/analyze` endpoint'ine `POST` (payload: text, language hint, correlation_id). Servisler arası iletişim **mTLS veya imzalı HMAC header** ile güvence altına alınır.
3. **AI Analizi (FastAPI):** Metin dil tespiti → sentiment + kategori sınıflandırma → JSON döner: `{sentiment_score, sentiment_label, category, confidence, keywords, model_version}`. Yanıt süresi SLO: p95 < 800 ms. Batch endpoint (`/v1/analyze/batch`, max 50 metin) toplu iş için sunulur.
4. **Doğrulama ve Kayıt:** Laravel yanıtı Form Request/DTO ile doğrular, `ai_analyses`'e yazar, `companies.analyzed_feedback_count`'u **atomik** (`increment` + DB lock) artırır.
5. **Gerçek Zamanlı Yayın:** `FeedbackAnalyzed` event'i tenant'a özel private channel'a (`private-company.{id}`) broadcast edilir.
6. **UI Güncellemesi:** Angular event'i yakalar, Inbox ve Overview KPI'ları optimistik günceller.

---

## 7. Kota ve Paywall Akışı

1. **Kayıt Güvenliği:** Kayıtta e-posta doğrulaması zorunlu; kurumsal e-posta kontrolü (disposable/free domain blocklist'i) yapılır. Suistimale karşı IP + device fingerprint (örn. FingerprintJS) sinyalleri **risk skoru** olarak değerlendirilir — tek başına engelleme kriteri değildir (yasal/KVKK notu: fingerprint verisi aydınlatma metninde belirtilmeli). Rate limiting: kayıt endpoint'i IP başına 5/saat.
2. **Sayaç:** Ücretsiz planda `quota_limit = 200`. Sayaç her başarılı analizde atomik artar; kalan kota her API yanıtında `X-Quota-Remaining` header'ı ile döner.
3. **Yumuşak Uyarı:** %80 kullanımda e-posta + in-app bildirim ("Kotanızın %80'i doldu").
4. **Paywall Tetiklenmesi:** Kota dolunca `AnalyzeFeedbackJob` yeni analizleri **duraklatır** (silmez — yorumlar `pending_analysis` durumunda birikir). API `402 Payment Required` + `{code: "QUOTA_EXCEEDED"}` döner.
5. **Yükseltme:** Angular 402'yi yakalar, akışı kilitleyen "Pro'ya Geç" modalını açar. Stripe Checkout / Iyzico ödeme akışı tamamlanınca **webhook** (`checkout.session.completed`) aboneliği aktive eder, birikmiş `pending_analysis` yorumları otomatik olarak yeniden kuyruğa alınır.
6. **Webhook Güvenliği:** İmza doğrulaması + `webhook_events.event_id` unique kontrolü ile replay/duplicate koruması.

---

## 8. Güvenlik Gereksinimleri (Eklenen Bölüm)

- OWASP Top 10 uyumu; tüm girişlerde server-side validation.
- Auth: Sanctum token + opsiyonel TOTP 2FA; oturum token'ları cihaz bazlı iptal edilebilir.
- Yetkilendirme: Rol bazlı (owner/admin/member) Laravel Policy'leri; her sorguda tenant scope.
- Secrets: `.env` dışında Vault/parameter store; repo'da asla credential bulunmaz.
- KVKK/GDPR: Veri silme (right to erasure) endpoint'i, veri işleme envanteri, feedback yazarlarının PII'ları maskelenebilir olmalı.
- API rate limiting: authenticated 120 req/dk, public 30 req/dk.

## 9. Test ve Kalite (Eklenen Bölüm)

- **Backend:** Pest/PHPUnit — unit + feature testleri; kota akışı, webhook idempotency ve tenant izolasyonu için zorunlu test senaryoları. Coverage hedefi ≥ %80.
- **AI Servisi:** pytest — şema doğrulama, dil tespiti edge case'leri, latency regresyon testi.
- **Frontend:** Jest (unit) + Playwright (E2E: kayıt → entegrasyon → paywall akışı).
- **Sözleşme testleri:** Laravel ↔ FastAPI arası OpenAPI şeması üzerinden contract test.
- **CI/CD:** GitHub Actions — lint (Pint, ESLint, Ruff) → test → Docker build → staging deploy; prod'a manuel onaylı promotion.

## 10. Teslimat Beklentileri

- Monorepo veya 3 repo (frontend / core / ai) — gerekçesiyle öner.
- Her servis için `README`, `docker-compose.dev.yml`, seed/factory verileri.
- OpenAPI (Swagger) dokümantasyonu iki backend için de otomatik üretilir.
- Mimari kararlar ADR (Architecture Decision Record) formatında kayıt altına alınır.

---

**Çıktı formatı:** Geliştirmeye başlamadan önce (1) mimari diyagram, (2) sprint bazlı yol haritası, (3) riskler ve varsayımlar listesi sun; onay sonrası kod üretimine geç.
