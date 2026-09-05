# Payment Webhook

OmniHear'da Stripe (global) ve Iyzico (TR) webhook'ları abonelik durumunu değiştirir ve kota dolduğu için bekleyen (`pending_analysis`) yorumları yeniden kuyruğa alır. Bu doküman, imza doğrulama, replay koruması ve HTTP yanıt kodu politikasını sabitler.

## Ne zaman okunur

Yeni bir webhook route/controller/job yazılacağı, mevcut biri değiştirileceği veya "abonelik neden aktive olmadı" gibi bir hata ayıklama isteği geldiğinde.

## Adımlar

### 1. Raw body ile imza doğrulama — JSON parse'dan ÖNCE

Route CSRF middleware'inden **hariç tutulur** ve genel public rate-limit grubuna girmez (sağlayıcı kendi retry temposunu yönetir).

```php
// routes/api.php
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:webhooks');
```

Stripe tarafı — `Webhook::constructEvent` **raw** `getContent()` üzerinde çalışır, decode edilmiş array üzerinde değil:

```php
public function __invoke(Request $request)
{
    try {
        $event = \Stripe\Webhook::constructEvent(
            $request->getContent(),
            $request->header('Stripe-Signature'),
            config('services.stripe.webhook_secret'),
        );
    } catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $e) {
        return response()->json(['code' => 'INVALID_SIGNATURE'], 400);
    }

    return $this->handle($event->type, $event->id, $event->toArray());
}
```

Iyzico tarafı — HMAC imzası raw body üzerinden hesaplanır, `hash_equals` ile sabit zamanlı karşılaştırılır:

```php
public function __invoke(Request $request)
{
    $raw = $request->getContent();
    $expected = hash_hmac('sha256', $raw, config('services.iyzico.webhook_secret'));

    if (! hash_equals($expected, $request->header('X-Iyzico-Signature', ''))) {
        return response()->json(['code' => 'INVALID_SIGNATURE'], 400);
    }

    $payload = json_decode($raw, true);
    return $this->handle($payload['eventType'], $payload['eventId'], $payload);
}
```

### 2. Önce `webhook_events` insert — replay koruması

İş mantığından **önce**, benzersiz `event_id` ile insert dene. `UniqueConstraintViolationException` yakalanırsa bu bir replay'dir — iş mantığını **çalıştırmadan** 200 dön.

```php
protected function handle(string $provider, string $eventId, array $payload): JsonResponse
{
    try {
        WebhookEvent::create([
            'provider' => $provider,
            'event_id' => $eventId,
            'payload' => $payload,
        ]);
    } catch (\Illuminate\Database\UniqueConstraintViolationException) {
        return response()->json(['status' => 'duplicate_ignored'], 200);
    }

    ProcessWebhookEventJob::dispatch($provider, $eventId, $payload);

    return response()->json(['status' => 'queued'], 200);
}
```

İş mantığı **HTTP yaşam döngüsünde çalışmaz** — `ProcessWebhookEventJob`'a devredilir. Sağlayıcının timeout'una takılmamak ve retry/backoff'u job katmanında yönetmek için.

### 3. Abonelik aktivasyonu → pending_analysis requeue

```php
class ProcessWebhookEventJob implements ShouldQueue
{
    public function handle(): void
    {
        match ($this->eventType) {
            'checkout.session.completed' => $this->activateSubscription(),
            default => Log::info('Unhandled webhook event type', ['type' => $this->eventType]),
        };
    }

    protected function activateSubscription(): void
    {
        $subscription = Subscription::updateOrCreate(
            ['provider_subscription_id' => $this->payload['subscription_id']],
            ['status' => 'active', 'current_period_start' => now(), ...],
        );

        $company = $subscription->company;

        Feedback::where('company_id', $company->id)
            ->where('status', 'pending_analysis')
            ->chunkById(200, function ($chunk) {
                foreach ($chunk as $feedback) {
                    AnalyzeFeedbackJob::dispatch($feedback);
                }
            });
    }
}
```

### 4. HTTP yanıt kodu politikası

| Durum | Kod | Neden |
|---|---|---|
| İmza geçersiz | **400** | Sağlayıcı bunu retry etmemeli, istek zaten bozuk. |
| İmza geçerli, `event_id` daha önce görüldü (replay) | **200** | Sağlayıcı retry etmeyi bıraksın; iş mantığı zaten bir kez çalıştı. |
| İmza geçerli, tip bilinmiyor/işlenemiyor ama beklenen bir durum | **200** | Sağlayıcı retry etmesin; olay job kuyruğuna zaten alındı veya kasıtlı yok sayıldı. |
| Beklenmeyen sistem hatası (DB down, job dispatch patladı) | **500** | Sağlayıcı retry etsin — biz kaybetmek istemiyoruz. |

## Zorunlu testler

Fixture'lar `backend/tests/Fixtures/webhooks/{stripe,iyzico}/*.json` altından yüklenir — **inline JSON yasak**, bu şekli kapsayan bir fixture varsa test onu kullanmak zorundadır.

- **Geçersiz imza → 400.** Bozuk/eksik signature header ile istek at, iş mantığının hiç çalışmadığını (`Queue::fake()` + `assertNotDispatched`) doğrula.
  ```php
  Queue::fake();
  $this->postJson('/webhooks/stripe', $fixturePayload, ['Stripe-Signature' => 'bozuk'])
      ->assertStatus(400)
      ->assertJson(['code' => 'INVALID_SIGNATURE']);
  Queue::assertNotPushed(ProcessWebhookEventJob::class);
  ```
- **Duplicate event → 200 + iş mantığı tam bir kez çalıştı.** Aynı `event_id` ile iki kez POST at; ikinci istek 200 dönmeli ama `Subscription` yalnızca bir kez güncellenmeli (job'u gerçek çalıştır, `Queue::fake()` kullanma bu testte).
  ```php
  $this->withValidSignature()->postJson('/webhooks/stripe', $fixture)->assertOk();
  $this->withValidSignature()->postJson('/webhooks/stripe', $fixture)->assertOk();
  $this->assertDatabaseCount('subscriptions', 1);
  ```
- **`checkout.session.completed` → subscription active + requeue edilen feedback sayısı doğrulandı.** Kurulumda N adet `pending_analysis` feedback oluştur, webhook'u işlet, `AnalyzeFeedbackJob`'ın tam N kez dispatch edildiğini doğrula.
  ```php
  Queue::fake();
  // ... webhook işlensin ...
  Queue::assertPushed(AnalyzeFeedbackJob::class, $pendingCount);
  $this->assertDatabaseHas('subscriptions', ['status' => 'active']);
  ```
