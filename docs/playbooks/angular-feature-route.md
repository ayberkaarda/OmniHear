# Angular Feature Route

OmniHear frontend'i Angular 18+ standalone component + Signals + Signal Store kullanır. Bu doküman, yeni bir özellik rotası eklerken lazy loading zincirini, guard sırasını, i18n eksiksizliğini ve WCAG 2.1 AA gereksinimlerini sabitler.

## Ne zaman okunur

Yeni bir sayfa/route, standalone component veya Signal Store slice eklenirken.

## Adımlar

### 1. Lazy route + guard sırası

Guard sırası önemli: önce kimlik doğrulama, sonra abonelik kontrolü. `SubscriptionGuard`'ın `AuthGuard`'dan önce çalışması, giriş yapmamış kullanıcıya abonelik hatası göstermek gibi yanlış bir deneyime yol açar.

```typescript
export const routes: Routes = [
  {
    path: 'inbox',
    canActivate: [AuthGuard, SubscriptionGuard],
    loadComponent: () => import('./inbox/inbox-page.component').then(m => m.InboxPageComponent),
  },
];
```

### 2. Standalone component — OnPush + Signals API

```typescript
@Component({
  selector: 'app-inbox-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CommonModule, FeedbackCardComponent],
  templateUrl: './inbox-page.component.html',
})
export class InboxPageComponent {
  private readonly store = inject(FeedbackStore);

  readonly companyId = input.required<string>();
  readonly filterChanged = output<FeedbackFilter>();

  readonly feedbacks = this.store.feedbacks;
  readonly unreadCount = computed(() => this.feedbacks().filter(f => !f.read).length);
  readonly selectedId = signal<string | null>(null);
}
```

### 3. Signal Store slice + WebSocket bağlama

Yeni analiz sonucu geldiğinde Inbox ve Overview **optimistik** güncellenir — sayfa yenilenmeden.

```typescript
export const FeedbackStore = signalStore(
  { providedIn: 'root' },
  withState<FeedbackState>({ feedbacks: [], loading: false }),
  withMethods((store, ws = inject(WebSocketService)) => ({
    connectRealtime(): void {
      ws.on<FeedbackAnalyzedEvent>('FeedbackAnalyzed', (event) => {
        patchState(store, (state) => ({
          feedbacks: state.feedbacks.map(f =>
            f.id === event.feedbackId ? { ...f, sentiment: event.sentiment, category: event.category } : f
          ),
        }));
      });
    },
  })),
);
```

`FeedbackAnalyzed` event'i backend'de Reverb üzerinden yayınlanır; frontend abonelik `ngOnInit`/`constructor` içinde değil, store'un kendi `connectRealtime()` metodunda kurulur ki test edilebilir kalsın.

### 4. i18n — her metin işaretli

Şablonda **her görünür metin** `i18n` attribute'una sahip olmalı; `placeholder`, `aria-label`, `title` gibi öznitelikler `i18n-*` ile işaretlenir:

```html
<h1 i18n="@@inbox.title">Gelen Kutusu</h1>
<input
  type="text"
  [placeholder]="'inbox.search.placeholder' | i18n"
  i18n-placeholder
  placeholder="Yorumlarda ara"
  i18n-aria-label
  aria-label="Yorum arama alanı"
/>
<button i18n-title title="Filtreleri temizle" (click)="clearFilters()">
  <span i18n>Temizle</span>
</button>
```

Çıkarma ve doldurma:

```bash
cd frontend && npx ng extract-i18n --output-path src/locale
```

Çıkan `messages.tr.xlf` içindeki **her** `<target>` doldurulmalı — boş `<target></target>` kırık faz demektir; `npm run i18n:check` bunu yakalar.

### 5. WCAG 2.1 AA kontrol listesi

- **Odak sırası:** Modal/panel açıldığında odak ilk etkileşimli elemana taşınır, kapanınca tetikleyici elemana geri döner.
- **`aria-live` toast:** Bildirim/toast bileşeni `aria-live="polite"` (kritik hatalarda `"assertive"`) taşır ki ekran okuyucu otomatik duyursun.
- **Tablo başlıkları:** Her `<th>` uygun `scope="col"` veya `scope="row"` taşır.
- **Sanal scroll listeleri** (`cdk-virtual-scroll-viewport`): her satır `role="row"` ve `aria-rowindex` taşır ki ekran okuyucu toplam/konum bilgisini kaybetmesin.
- **Klavye navigasyonu:** Tüm etkileşimli elemanlar yalnızca Tab/Shift+Tab/Enter/Space ile erişilebilir; özel widget'larda (dropdown, sekme) ok tuşu navigasyonu ARIA APG paternine uyar.

```html
<div class="toast" role="status" aria-live="polite" i18n>Yorum analiz edildi</div>
<table>
  <thead>
    <tr><th scope="col" i18n>Yazar</th><th scope="col" i18n>Duygu</th></tr>
  </thead>
</table>
```

## Zorunlu testler

- **Jest spec:** Component'in signal tabanlı state geçişlerini (ör. `selectedId` değişince `computed` doğru güncelleniyor mu) kapsayan en az bir test.
  ```typescript
  it('marks feedback as read when selected', () => {
    fixture.componentInstance.selectedId.set('f-1');
    expect(fixture.componentInstance.unreadCount()).toBe(expectedCount);
  });
  ```
- **`ng build --configuration production` budget çıktısı:** initial bundle < 250KB olduğunu build log'undan doğrula; budget dosyasını gevşeterek "geçirme".
- **Route gerçekten lazy mi:** Build çıktısında yeni sayfa için ayrı bir chunk dosyası üretildiğini doğrula (`dist/**/chunk-*.js` içinde route'a özgü component adı geçmeli, ana `main.js` içinde değil).
- WCAG regresyonu: en azından `aria-live` ve `scope` attribute'larının şablonda var olduğunu doğrulayan bir DOM testi.
