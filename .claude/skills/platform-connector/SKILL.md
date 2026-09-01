---
name: platform-connector
description: OmniHear'a App Store, Google Play, Zendesk, Trustpilot gibi yeni bir kanal entegrasyonu/connector eklerken veya mevcut birini değiştirirken kullanılır. Tetikleyiciler entegrasyon, integration, connector, App Store, Google Play, Zendesk, Trustpilot, FetchFeedbackJob, ingestion, senkronizasyon, sync.
---

# Platform Connector

OmniHear farklı kanallardan (App Store, Google Play, Zendesk, Trustpilot, e-posta, sosyal medya) geri bildirim çeker. Bu skill, yeni bir platform connector'ının artımlı çekim, credential güvenliği ve hata izolasyonu kurallarına uymasını sağlar.

## Ne zaman yükle

Yeni bir platform için connector eklenirken, mevcut bir connector'ın senkron mantığı değiştirilirken, ya da "entegrasyon neden duruyor/hata veriyor" tarzı bir hata ayıklama isteğinde.

## Adımlar

### 1. `PlatformConnector` interface

```php
interface PlatformConnector
{
    /** @return iterable<array{external_id: string, author: ?string, body: string, source_url: ?string, published_at: ?string, raw_payload: array}> */
    public function fetchSince(?string $cursor): iterable;

    public function healthCheck(): ConnectorHealth;
}
```

Her platform kendi implementasyonunu `App\Connectors\{Platform}Connector` altında yazar (ör. `ZendeskConnector`, `TrustpilotConnector`).

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
$cursor = $integration->sync_cursor;
$rows = [];

foreach ($connector->fetchSince($cursor) as $item) {
    $rows[] = [
        'integration_id' => $integration->id,
        'company_id' => $integration->company_id,
        'external_id' => $item['external_id'],
        'author' => $item['author'],
        'body' => $this->maskPii($item['body']),
        'source_url' => $item['source_url'],
        'published_at' => $item['published_at'],
        'raw_payload' => $item['raw_payload'],
    ];
    $cursor = $item['external_id']; // veya platformun sağladığı native cursor/timestamp
}

Feedback::upsert($rows, uniqueBy: ['integration_id', 'external_id'], update: ['author', 'body', 'source_url']);
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
- **Cursor ilerliyor:** Senkron sonrası `integrations.sync_cursor` fixture'daki son kaydın değerine güncellenmiş olmalı; bir sonraki `fetchSince()` çağrısına bu cursor'ın geçtiğini doğrula (baştan tarama yapılmadığının kanıtı).
- **Hata durumunda credentials sızmıyor:** Connector exception fırlattığında `sync_error` alanının credential/token/secret içermediğini string araması ile doğrula; ayrıca log çıktısında `credentials` anahtarının hiç görünmediğini doğrula.
