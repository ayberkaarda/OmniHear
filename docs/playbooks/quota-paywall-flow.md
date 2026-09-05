# Quota Paywall Flow

Ücretsiz plan `quota_limit = 200`. Kota dolunca analiz **durmaz** — yorumlar `pending_analysis` durumunda birikir, kota yükseltilince otomatik requeue edilir. Bu doküman sayacın atomikliğini, 402 gövde şeklini ve Angular tarafındaki paywall modalını sabitler.

## Ne zaman okunur

Kota sayacına dokunan bir job/controller, 402 yanıtı üreten bir middleware, ya da paywall modalı/interceptor'ı yazılacağı zaman.

## Backend

### 1. Atomik sayaç — koşullu UPDATE, `lockForUpdate` değil

Race condition'ı satır kilidiyle değil, tek atomik SQL ifadesiyle çöz. `lockForUpdate` + ayrı SELECT/UPDATE arasında paralel job'lar yine yarışabilir; koşullu UPDATE tek round-trip'te atomiktir.

```php
$result = DB::selectOne(
    <<<'SQL'
    UPDATE companies
    SET analyzed_feedback_count = analyzed_feedback_count + 1
    WHERE id = ? AND analyzed_feedback_count < quota_limit
    RETURNING analyzed_feedback_count, quota_limit
    SQL,
    [$companyId],
);

if ($result === null) {
    // 0 satır döndü → kota dolu
    $feedback->update(['status' => 'pending_analysis']);
    return; // job BAŞARILI biter — fail() yok, retry yok, DLQ yok
}
```

### 2. `AnalyzeFeedbackJob` kota dolunca başarılı biter

```php
class AnalyzeFeedbackJob implements ShouldQueue
{
    public function handle(): void
    {
        $incremented = $this->incrementQuotaAtomically($this->feedback->company_id);

        if (! $incremented) {
            $this->feedback->update(['status' => 'pending_analysis']);
            return; // exception fırlatma — bu bir hata değil, beklenen bir akış
        }

        // ... AI servisine gönder, ai_analyses satırını yaz ...
    }
}
```

`pending_analysis` feedback'ler **silinmez**, sonraki abonelik aktivasyonunda (`payment-webhook` dokümanındaki requeue akışı) tekrar dispatch edilir.

### 3. HTTP 402 gövdesi + `X-Quota-Remaining` header

```php
// app/Http/Middleware/AttachQuotaHeader.php
public function handle(Request $request, Closure $next)
{
    $response = $next($request);
    if ($company = $request->user()?->company) {
        $remaining = max(0, $company->quota_limit - $company->analyzed_feedback_count);
        $response->headers->set('X-Quota-Remaining', (string) $remaining);
    }
    return $response;
}
```

Kota kontrolü gereken uç noktalarda (ör. manuel yeniden analiz tetikleyen endpoint) 402:

```php
if ($company->analyzed_feedback_count >= $company->quota_limit) {
    return response()->json([
        'code' => 'QUOTA_EXCEEDED',
        'message' => __('quota.exceeded'),
        'quota_limit' => $company->quota_limit,
        'remaining' => 0,
    ], 402);
}
```

### 4. %80 eşiği — idempotent tek seferlik bildirim

Eşiği geçen her artırımda yeniden bildirim göndermemek için `companies` tablosunda (veya ayrı bir flag tablosunda) idempotent bir bayrak tut:

```php
if (! $company->quota_warning_sent_at && $company->analyzed_feedback_count >= $company->quota_limit * 0.8) {
    $company->update(['quota_warning_sent_at' => now()]);
    Notification::send($company->owner, new QuotaWarningNotification($company));
    // in-app bildirim de aynı transaction'da tetiklenir
}
```

Plan yükseltilince veya kota resetlenince bu bayrak sıfırlanır.

## Frontend (Angular)

### 5. Interceptor → PaywallStore

```typescript
export const quotaInterceptor: HttpInterceptorFn = (req, next) => {
  const paywall = inject(PaywallStore);
  return next(req).pipe(
    catchError((err: HttpErrorResponse) => {
      if (err.status === 402 && err.error?.code === 'QUOTA_EXCEEDED') {
        paywall.open(err.error);
      }
      return throwError(() => err);
    }),
  );
};
```

### 6. Paywall modalı — akışı kilitleyen modal

Kullanıcı Esc ile kapatamaz, arka planı karartıp odağı tuzağa alır — kota bitince bilinçli bir yükseltme kararı verilmeden akışa devam edilemez.

```html
<div
  role="dialog"
  aria-modal="true"
  i18n-aria-label
  aria-label="Kota doldu"
  cdkTrapFocus
  cdkTrapFocusAutoCapture
  (keydown.escape)="$event.preventDefault()"
>
  <h2 i18n>Ücretsiz kotanız doldu</h2>
  <button (click)="upgrade()" i18n>Planı yükselt</button>
</div>
```

`/402` route'u, doğrudan bağlantıyla (ör. e-posta bildirimi) gelen kullanıcıyı da aynı modala yönlendirir.

## Zorunlu testler

- **Yarış koşulu testi:** `quota_limit = 3` iken 5 job'u paralel/eşzamanlı dispatch et (ör. `DB::transaction` dışı gerçek eşzamanlı worker simülasyonu veya `pcntl_fork`/ayrı process ile), sayaç tam **3** artmalı, kalan 2 job'un feedback'i `pending_analysis` olmalı.
  ```php
  // 5 eşzamanlı çağrı, quota_limit=3
  $this->assertEquals(3, $company->fresh()->analyzed_feedback_count);
  $this->assertEquals(2, Feedback::where('status', 'pending_analysis')->count());
  ```
- **402 gövde şekli:** `code`, `message`, `quota_limit`, `remaining` alanlarının tam olarak beklenen şekilde döndüğünü doğrula.
- **Requeue sonrası sayaç doğru:** Abonelik aktive olup `pending_analysis` feedback'ler yeniden işlendiğinde `analyzed_feedback_count` yeni `quota_limit`'i aşmadan doğru artıyor mu.
- Angular: `PaywallStore.open()` çağrıldığında modalın `aria-modal="true"` ile render edildiğini ve Esc tuşunun modalı kapatmadığını doğrulayan bir Jest/Testing Library testi.
