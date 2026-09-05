# Platform Connector

OmniHear farklı kanallardan (App Store, Google Play, Zendesk, Trustpilot, e-posta, sosyal medya) geri bildirim çeker. Bu doküman, yeni bir platform connector'ının artımlı çekim, credential güvenliği ve hata izolasyonu kurallarına uymasını sağlar.

## Ne zaman okunur

Yeni bir platform için connector eklenirken, mevcut bir connector'ın senkron mantığı değiştirilirken, ya da "entegrasyon neden duruyor/hata veriyor" tarzı bir hata ayıklama isteğinde.

## Adımlar

### 1. `PlatformConnector` interface

**Sayfa döner, iterable değil.** Bir `iterable` "akış bitti mi" ile "bu sayfa boş geldi" arasını ayıramaz ve platformun opak cursor'ını taşıyamaz — canlı feed ölçümleri bu ayrımın zorunlu olduğunu gösterdi (§9).

```php
interface PlatformConnector
{
    public function fetchPage(?string $cursor): ConnectorPage;

    public function limits(): ConnectorLimits;

    public function healthCheck(): ConnectorHealth;
}

final readonly class ConnectorLimits
{
    public function __construct(
        public int $maxPagesPerRun,           // App Store: 10 (platform sınırı, §9)
        public int $maxConsecutiveEmptyPages, // App Store: 3 (aralıklı boş sayfa, §9)
    ) {}
}

final readonly class ConnectorItem
{
    public function __construct(
        public string  $externalId,   // App Store: entry.id.label
        public ?string $author,
        public string  $body,
        public ?string $sourceUrl,
        public ?string $publishedAt,  // ISO 8601 — App Store: entry.updated.label
        public ?int    $rating,       // App Store: im:rating; platformda yoksa null
        public array   $rawPayload,
    ) {}
}

final readonly class ConnectorPage
{
    /** @param list<ConnectorItem> $items */
    public function __construct(
        public array   $items,
        public bool    $hasMore,     // akışın durumu SADECE buradan okunur
        public ?string $nextCursor,  // opak, connector'a ait; hasMore=true iken non-null ZORUNLU
        public ?string $watermark,   // bu sayfadaki en büyük publishedAt; sayfa boşsa null
    ) {}
}
```

Her platform kendi implementasyonunu `App\Connectors\{Platform}Connector` altında yazar.

#### Semantik kuralları (ihlali veri kaybettirir)

1. **`items === []` akış hakkında hiçbir şey söylemez.** Akışın bitip bitmediğini yalnızca `hasMore` söyler. Boş sayfa + `hasMore=true` = "devam et". Bu teorik değil: App Store feed'i aralıklı olarak boş sayfa döndürüyor (§9).
2. `hasMore=true` ⇒ `nextCursor !== null`. DTO constructor'ında assert et.
3. `hasMore=false` ⇒ koşum biter; `nextCursor` bu koşumun **bir sonraki koşum için** kalıcı cursor'ıdır (null ise cursor değişmez).
4. **Cursor opaktır.** Connector kendi içeriğini kendi kodlar (App Store: `{"page":3,"watermark":"2026-08-30T…"}`). Çağıran taraf içini asla yorumlamaz.
5. **Upsert sayfa başına, cursor güncellemesi koşum sonunda.** Sayfa 7'de hata alınırsa 1–6 yazılmış ama cursor eskide kalmış olur; sonraki koşum I2 (`UNIQUE(integration_id, external_id)`) sayesinde idempotent olarak tekrar çeker. Veri kaybı yok, çift kayıt yok.
6. **Çağıranın güvenlik ağı:** `FetchFeedbackJob`, `limits()`'ten gelen iki tavan aşılırsa `hasMore`'a bakmadan durur, uyarı loglar (`integration_id`, `platform`, `pages_fetched` — credential veya metin **yok**), cursor'ı son başarılı sayfanın değerine yazar. Connector'ın hatalı `hasMore=true` döndürmesi sonsuz döngüye dönüşemez.

### 2. Credential güvenliği

```php
class Integration extends Model
{
    use BelongsToCompany;

    protected $hidden = ['credentials'];
    protected $casts = ['credentials' => 'encrypted:array'];
}
```

`credentials` alanı **hiçbir koşulda** loglanmaz veya serialize edilmez. Hata mesajlarında, exception'larda, job payload'larında credential'ın kendisi değil, entegrasyon ID'si taşınır:

```php
// YANLIŞ — credentials sızabilir
Log::error('Sync failed', ['integration' => $integration->toArray()]);

// DOĞRU
Log::error('Sync failed', ['integration_id' => $integration->id, 'platform' => $integration->platform]);
```

### 3. Cursor tabanlı artımlı çekim — tam tarama yasak

```php
$limits = $connector->limits();
$cursor = $integration->sync_cursor;
$pages = 0;
$emptyStreak = 0;

do {
    $page = $connector->fetchPage($cursor);
    $pages++;

    if ($page->items === []) {
        // Boş sayfa akışın bittiği anlamına GELMEZ (§9). Sadece art arda
        // gelen boş sayfaları sayarız; durma kararı hasMore ve tavanlara ait.
        $emptyStreak++;
    } else {
        $emptyStreak = 0;

        $rows = array_map(fn (ConnectorItem $i) => [
            'integration_id' => $integration->id,
            'company_id'     => $integration->company_id,
            'external_id'    => $i->externalId,
            'author'         => $i->author,
            'body'           => $this->maskPii($i->body),
            'source_url'     => $i->sourceUrl,
            'published_at'   => $i->publishedAt,
            'raw_payload'    => $i->rawPayload,
        ], $page->items);

        // Upsert sayfa başına: koşum yarıda kesilse bile çekilen veri yazılmış olur.
        Feedback::upsert($rows, uniqueBy: ['integration_id', 'external_id'], update: ['author', 'body', 'source_url']);
    }

    $cursor = $page->nextCursor ?? $cursor;

    $capped = $pages >= $limits->maxPagesPerRun
           || $emptyStreak >= $limits->maxConsecutiveEmptyPages;

    if ($capped) {
        Log::warning('Connector run capped', [
            'integration_id' => $integration->id,
            'platform'       => $integration->platform,
            'pages_fetched'  => $pages,
            'empty_streak'   => $emptyStreak,
        ]);
    }
} while ($page->hasMore && ! $capped);

// Cursor yalnızca koşum sonunda kalıcılaşır.
$integration->update(['sync_cursor' => $cursor, 'last_synced_at' => now()]);
```

Her platform çağrısı ilk seferden itibaren `cursor`/timestamp saklar (`integrations.sync_cursor`); her senkronda **baştan tüm veriyi çekmek yasaktır** — hem rate limit hem maliyet açısından.

### 4. Platform bazlı throttle

```php
Redis::throttle('connector:' . $integration->platform)
    ->allow(60)->every(60)
    ->then(function () use ($connector, $integration) {
        // fetch
    }, function () {
        // limit doldu — job'u geciktir, hata sayma
        return $this->release(30);
    });
```

### 5. PII maskeleme kancası

```php
protected function maskPii(string $body): string
{
    return preg_replace('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i', '[email]', $body);
    // telefon/kart numarası paternleri connector'a özgü genişletilebilir
}
```

### 6. Hata → status + sync_error (credentials içermeyen)

```php
try {
    $this->sync($integration);
} catch (ConnectorException $e) {
    $integration->update([
        'status' => 'error',
        'sync_error' => $e->getSafeMessage(), // credential/token asla burada olmaz
    ]);
}
```

`ConnectorException::getSafeMessage()` sınıf içinde tanımlanır ve ham HTTP yanıtı/header/credential döndürmez, yalnızca "rate limit aşıldı", "kimlik doğrulama reddedildi" gibi genel bir mesaj döner.

### 7. Retry + DLQ

```php
class FetchFeedbackJob implements ShouldQueue
{
    public $tries = 5;
    public $backoff = [10, 30, 60, 300, 900]; // exponential

    public function failed(\Throwable $e): void
    {
        $this->integration->update(['status' => 'error', 'sync_error' => 'Sync failed after retries']);
        // DLQ'ya düşer — Horizon failed_jobs tablosu
    }
}
```

### 8. Scheduler

```php
// routes/console.php veya app/Console/Kernel.php
Schedule::call(function () {
    Integration::where('status', 'active')->each(
        fn ($integration) => FetchFeedbackJob::dispatch($integration)
    );
})->everyFiveMinutes();
```

## Zorunlu testler

Fixture'lar `backend/tests/Fixtures/platforms/<platform>/*.json` altından — inline JSON yasak.

- **`Http::fake()` + fixture ile başarılı çekim:** Fixture'daki N kaydın `feedbacks` tablosuna yazıldığını doğrula.
  ```php
  Http::fake(['*/reviews*' => Http::response(
      json_decode(file_get_contents(base_path('tests/Fixtures/platforms/trustpilot/page1.json')), true)
  )]);
  ```
- **Aynı `external_id` iki kez çekilince tek kayıt:** Aynı fixture'ı iki kez işlet, `feedbacks` tablosunda `(integration_id, external_id)` başına tek satır olduğunu doğrula (`upsert` davranışı).
- **Cursor ilerliyor:** Senkron sonrası `integrations.sync_cursor` güncellenmiş olmalı; bir sonraki `fetchPage()` çağrısına bu cursor'ın geçtiğini doğrula (baştan tarama yapılmadığının kanıtı).
- **Hata durumunda credentials sızmıyor:** Connector exception fırlattığında `sync_error` alanının credential/token/secret içermediğini string araması ile doğrula; ayrıca log çıktısında `credentials` anahtarının hiç görünmediğini doğrula.
- **Boş sayfa akışı durdurmuyor:** `page-empty-transient.json` → `hasMore=true` ile döndüğünde koşum devam etmeli. Bu testin kırmızıya dönmesi, aralıklı boş sayfada sessiz veri kaybı demektir.
- **Derinlik sınırı:** `page-depth-exceeded.txt` (HTTP 400) alındığında connector `ConnectorException` fırlatmalı ve **cursor ilerlememeli**.
- **Tavan güvenlik ağı:** Connector kalıcı olarak `hasMore=true` döndüren sahte bir implementasyonla değiştirildiğinde, koşum `limits()->maxPagesPerRun` sayfada durmalı (sonsuz döngü olmamalı).

## 9. Doğrulanmış platform davranışları

App Store public review feed'i (`https://itunes.apple.com/<storefront>/rss/customerreviews/page=N/id=<appId>/sortBy=mostRecent/json`) üzerinde **2026-09-02'de ölçüldü**. Bunlar tahmin değil, kaydedilmiş gerçek yanıtlar — fixture'lar `contracts/fixtures/platforms/appstore/` altında.

| Davranış | Ölçüm | Tasarıma etkisi |
|---|---|---|
| Kimlik gerektirmiyor | US feed HTTP 200, 40 KB, 466 ms | Klonlayan biri hesap açmadan gerçek veri görebilir |
| Gerçek TR içerik | TR storefront `page=3` → 50 Türkçe yorum | Dil tespiti ve TR sentiment gerçek veriyle sınanabilir |
| **Derinlik sınırı 10** | `page=11` → **HTTP 400**, gövde gzip'li düz metin: `CustomerReviews RSS page depth is limited to 10` | `maxPagesPerRun = 10`. Tam tarama fiilen imkânsız → watermark **zorunlu**, tercih değil |
| **Aralıklı boş sayfa** | `page=1` bir ölçümde 0 entry, hemen sonraki 5 ölçümde 50 entry döndürdü | "Boş sayfa = akış bitti" mantığı **sessizce veri kaybettirir**. `hasMore` semantiği (§1.1) bunun içindir |
| Alan eşlemesi | `entry.id.label` → `externalId` · `entry.updated.label` (ISO 8601) → `publishedAt`/watermark · `im:rating` → `rating` · `author.name.label` → `author` · `content.label` → `body` | — |

> **Tuzak:** `page=11` yanıtını `curl` ile `--compressed` olmadan çekersen gzip çözülmez ve gövde "bozuk JSON" gibi görünür. Bozuk değil — belgelenmiş bir 400. HTTP durum kodunu oku, gövdeyi tahmin etme.
