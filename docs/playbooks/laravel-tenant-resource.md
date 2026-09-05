# Laravel Tenant Resource

OmniHear'da her satır bir `companies.id`'ye bağlıdır. Bu doküman, yeni bir tenant-scoped kaynak (model + migration + policy + factory + resource + endpoint) eklerken tenant izolasyonunun **hiçbir katmanda** delinmemesini sağlar.

## Ne zaman okunur

Yeni bir migration, model, policy, factory, API resource veya CRUD endpoint yazılacağı zaman.

## Adımlar

### 1. Migration

Her tenant-scoped tabloda `company_id` FK ve sorgu paternine uygun composite index olur:

```php
Schema::create('feedbacks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
    $table->string('external_id');
    $table->string('author')->nullable();
    $table->text('body');
    $table->string('source_url')->nullable();
    $table->timestamp('published_at')->nullable();
    $table->jsonb('raw_payload')->nullable();
    $table->timestamps();

    $table->unique(['integration_id', 'external_id']);
    $table->index(['company_id', 'created_at']);
});
```

JSONB alanlar için `->jsonb()` kullan, düz `->json()` **kullanma** — Postgres'te indekslenebilirlik ve sorgu operatörleri (`->>`, `@>`) farklıdır.

### 2. `BelongsToCompany` trait

Global scope ile okuma tarafını, `creating` event'i ile yazma tarafını kilitle. `company_id` **hiçbir zaman** request body'sinden alınmaz — her zaman oturumdaki kullanıcının `company_id`'sinden.

```php
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if ($companyId = auth()->user()?->company_id) {
                $builder->where($builder->getModel()->getTable() . '.company_id', $companyId);
            }
        });

        static::creating(function (Model $model) {
            if (! $model->company_id && auth()->check()) {
                $model->company_id = auth()->user()->company_id;
            }
        });
    }
}
```

`DB::table()` bu global scope'u **atlar** — yasak. Her sorgu Eloquent üzerinden veya scope'u manuel taşıyarak yazılır.

### 3. Model

```php
class Feedback extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['integration_id', 'external_id', 'author', 'body', 'source_url', 'published_at', 'raw_payload'];

    protected $hidden = []; // credentials taşıyan modellerde ilgili alanlar buraya

    protected $casts = [
        'raw_payload' => 'array',
        'published_at' => 'datetime',
    ];
}
```

`integrations.credentials` gibi hassas alanlar için:

```php
protected $hidden = ['credentials'];
protected $casts = ['credentials' => 'encrypted:array'];
```

### 4. Policy — owner/admin/member matrisi

Her policy metodunda rolleri tablo gibi düşün, kod olarak da öyle yaz:

| Eylem | owner | admin | member |
|---|---|---|---|
| viewAny | ✓ | ✓ | ✓ |
| view | ✓ | ✓ | ✓ |
| create | ✓ | ✓ | ✗ |
| update | ✓ | ✓ | ✗ |
| delete | ✓ | ✗ | ✗ |

```php
class FeedbackPolicy
{
    public function view(User $user, Feedback $feedback): bool
    {
        return $user->company_id === $feedback->company_id;
    }

    public function delete(User $user, Feedback $feedback): bool
    {
        return $user->company_id === $feedback->company_id && $user->role === 'owner';
    }
}
```

Yetkisiz erişimde policy `false` döner, controller bunu **404**'e çevirir (bkz. Zorunlu testler) — 403 değil.

### 5. Factory

```php
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'integration_id' => Integration::factory(),
            'external_id' => fake()->uuid(),
            'body' => fake()->paragraph(),
            'published_at' => now(),
        ];
    }
}
```

Testte açıkça `->for(Company::factory())` ver ki iki farklı tenant senaryosu kurulabilsin:

```php
$companyA = Company::factory()->create();
$companyB = Company::factory()->create();
$feedback = Feedback::factory()->for($companyA)->create();
```

### 6. FormRequest + API Resource

```php
class StoreFeedbackRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string'],
            'body' => ['required', 'string'],
        ];
        // company_id kuralı YOK — request'ten asla alınmaz
    }
}

class FeedbackResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
        // company_id ve credentials benzeri alanlar API'ye asla çıkmaz
    }
}
```

## Zorunlu testler

- **Cross-tenant erişim 404 döner, 403 değil.** Şirket A'nın kullanıcısı Şirket B'nin `feedback` ID'sine `GET /api/feedbacks/{id}` yaparsa yanıt 404 olmalı — varlığın var olduğunu bile sızdırma.
  ```php
  $this->actingAs($userA)->getJson("/api/feedbacks/{$feedbackOfCompanyB->id}")->assertNotFound();
  ```
- **Tenant B'nin verisi tenant A'nın listesinde çıkmaz.** `GET /api/feedbacks` yanıtında B'ye ait ID hiç görünmemeli.
- **Mass-assignment testi:** `company_id` request body'sinde farklı bir şirket ID'si gönderilse bile kayıt oturumdaki kullanıcının şirketine yazılır.
  ```php
  $this->actingAs($userA)->postJson('/api/feedbacks', [...$payload, 'company_id' => $companyB->id])
      ->assertCreated();
  $this->assertDatabaseHas('feedbacks', ['company_id' => $companyA->id]);
  $this->assertDatabaseMissing('feedbacks', ['company_id' => $companyB->id]);
  ```
- Policy matrisindeki her rol için en az bir yetkili/yetkisiz testi (owner/admin/member × create/update/delete).
