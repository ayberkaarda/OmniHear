# AI Contract Sync

`ai-service` (FastAPI/Pydantic v2) ve `backend` (Laravel) arasındaki `/v1/analyze` sözleşmesi tek bir kaynaktan (`contracts/ai-openapi.json`) türer. Bu doküman, şema değişikliğinde iki tarafın senkron kalmasını ve kırıcı değişikliklerin `model_version` bump'ı gerektirdiğini sabitler.

## Ne zaman okunur

`ai-service`'teki bir Pydantic modeli değiştiğinde, Laravel DTO/FormRequest'i güncellenirken, veya `/v1/analyze` ya da `/v1/analyze/batch` uç noktalarına dokunulduğunda.

## Adımlar

### 1. Pydantic v2 modeli değiştir

```python
from pydantic import BaseModel, Field
from typing import Literal

class AnalyzeRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=10_000)
    language_hint: str | None = None

class AnalyzeResult(BaseModel):
    sentiment_score: float = Field(..., ge=-1.0, le=1.0)
    sentiment_label: Literal["positive", "neutral", "negative"]
    category: Literal["bug", "feature_request", "praise", "complaint"]
    confidence: float = Field(..., ge=0.0, le=1.0)
    keywords: list[str] = Field(default_factory=list)
    model_version: str
```

Batch uç noktası tekil ile **aynı** `AnalyzeResult` şeklini, liste olarak döner ve 50 metin sınırını Pydantic seviyesinde zorlar:

```python
class BatchAnalyzeRequest(BaseModel):
    items: list[AnalyzeRequest] = Field(..., min_length=1, max_length=50)
```

### 2. OpenAPI şemasını yeniden üret

```bash
cd ai-service
python -c "
import json
from app.main import app
schema = app.openapi()
schema['info'].pop('version', None)  # normalize — gürültülü diff üretmesin
json.dump(schema, open('../contracts/ai-openapi.json', 'w'), indent=2, sort_keys=True)
"
```

`info.version` alanı diff'ten hariç tutulur çünkü her build'de değişebilir; asıl versiyon takibi `model_version` alanındadır.

### 3. Laravel DTO + FormRequest'i eşitle

```php
final class AnalyzeResultDto
{
    public function __construct(
        public readonly float $sentimentScore,
        public readonly string $sentimentLabel,
        public readonly string $category,
        public readonly float $confidence,
        public readonly array $keywords,
        public readonly string $modelVersion,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sentimentScore: $data['sentiment_score'],
            sentimentLabel: $data['sentiment_label'],
            category: $data['category'],
            confidence: $data['confidence'],
            keywords: $data['keywords'],
            modelVersion: $data['model_version'],
        );
    }
}
```

```php
class AnalyzeResultRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sentiment_score' => ['required', 'numeric', 'between:-1,1'],
            'sentiment_label' => ['required', Rule::in(['positive', 'neutral', 'negative'])],
            'category' => ['required', Rule::in(['bug', 'feature_request', 'praise', 'complaint'])],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'model_version' => ['required', 'string'],
            'keywords' => ['required', 'array'],
        ];
    }
}
```

### 4. HMAC header + correlation_id — zorunlu alanlar

Her istek/yanıt çiftinde bulunmalı:

```php
$signature = hash_hmac('sha256', $body, config('services.ai.hmac_secret'));
$response = Http::withHeaders([
    'X-Signature' => $signature,
    'X-Correlation-Id' => (string) Str::uuid(),
])->post(config('services.ai.base_url') . '/v1/analyze', $payload);
```

FastAPI tarafı `X-Signature`'ı doğrular, geçersizse 401 döner; `X-Correlation-Id` yoksa 400 döner (loglama/izleme için zorunlu).

### 5. Kırıcı vs geriye uyumlu değişiklik ayrımı

| Değişiklik | Sınıf | Aksiyon |
|---|---|---|
| Yeni opsiyonel alan ekleme | Geriye uyumlu | Doğrudan yayınla, `model_version` sabit kalabilir |
| Var olan alanı silme | Kırıcı | `model_version` bump + eski `ai_analyses` satırları için re-analyze planı |
| Alan yeniden adlandırma | Kırıcı | Aynı — eski isim bir süre alias olarak korunabilir |
| Tip daraltma (ör. `str` → `Literal[...]`) | Kırıcı | Aynı — mevcut veride yeni enum dışına düşen değer var mı taranmalı |

Kırıcı değişiklikte: `ai_analyses.model_version` alanı yeni sürümü taşır, eski sürümle analiz edilmiş satırlar silinmez ama bir migration/komutla yeniden kuyruğa alınabilecek şekilde işaretlenir (`SELECT id FROM ai_analyses WHERE model_version < 'X'`).

### 6. SLO — latency regresyonu

`/v1/analyze` için p95 < 800ms hedefi vardır. Şema değişikliği ek işlem (ör. yeni bir model çağrısı) getiriyorsa latency testini mutlaka çalıştır.

## Zorunlu testler

- **pytest şema doğrulama:** Geçersiz `sentiment_score` (ör. `1.5`) `AnalyzeResult`'ta `ValidationError` fırlatmalı.
  ```python
  def test_sentiment_score_out_of_range_rejected():
      with pytest.raises(ValidationError):
          AnalyzeResult(sentiment_score=1.5, sentiment_label="positive", ...)
  ```
- **Pest contract test:** Aynı senaryo Laravel `AnalyzeResultRequest` tarafında da reddedilmeli.
- **İki taraf aynı fixture'ı geçirir:** `contracts/fixtures/analyze/*.json` altındaki her fixture hem pytest'te (`AnalyzeResult.model_validate(fixture)`) hem Pest'te (`AnalyzeResultRequest` validation) hatasız geçmeli — inline JSON değil, bu fixture'lar kullanılır.
- **Batch tutarlılığı:** 50 metinlik istek kabul edilir, 51. metin `max_length` ihlali ile reddedilir (pytest + Pest ikisinde).
- **Latency regresyon testi:** `/v1/analyze` p95 < 800ms; CI'da örnekleme ile ölçülüp eşik aşımında test kırmızı olur.
