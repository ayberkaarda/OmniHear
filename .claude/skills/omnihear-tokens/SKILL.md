---
name: omnihear-tokens
description: OmniHear renk/token kuralları — sentiment (duygu) diverging skalası (turuncu↔mavi, kırmızı-yeşil YASAK), category (kategori) rozetleri, platform/kaynak ikonları, integration status, quota/402 renkleri, light/dark semantic token sözlüğü ve Tailwind v4 @theme eşlemesi. Yeni Angular bileşeni, badge/rozet, chip/çip, KPI kartı, inbox satırı, filtre yazarken veya tokens.json / tokens.css / Figma Variables değiştirirken yükle. Triggers: sentiment color, duygu rengi, kategori rozeti, category badge, token, tema, dark mode, koyu tema, platform ikonu, quota banner, paywall, 402, kontrast, WCAG, tailwind theme, figma variables, ThemeService.
---

# OmniHear Tokens

Bu skill **değer** verir; yöntem vermez. Grafik türü/legend/tooltip/stat tile → `dataviz`. Görsel yön, tipografi kişiliği, landing kompozisyonu → `frontend-design`. Bu skill yalnızca OmniHear'a özel token adlarını, hex'leri, veri boyutu→token eşlemesini ve çarpışma yasaklarını taşır. `dataviz`'in `references/palette.md` "swap" noktasını §2'deki değerler doldurur.

## 1. Karar tablosu — veri boyutu → kanal

Renk körlüğünde 2 kanal kalır (mavi↔sarı ekseni + luminans). Bu yüzden her boyut **ikon + metin + konum** ile yedeklenir; renk tek başına anlam taşımaz.

| Boyut | Hue | Şekil | İkon (lucide) | aria-label şablonu |
|---|---|---|---|---|
| `sentiment_score` / `sentiment_label` | turuncu (neg) ↔ mavi (poz), gri (nötr) | **dolu** nokta / çift yönlü bar, yüksek kroma | `trending-down` / `minus` / `trending-up` | `Duygu: {label}, {score}` |
| `category` | fuşya / yeşil / slate / teal | **outline** rozet + tint, düşük kroma | `megaphone` / `heart` / `bug` / `lightbulb` | `Kategori: {label}` |
| `platform` | **renk yok** — `currentColor`, `text-tertiary` | monokrom glif + metin | platform glifi | `Kaynak: {name}` |
| `integrations.status` | yeşil / kırmızı / gri | nokta + metin | `check-circle` / `x-circle` / `pause-circle` | `Durum: {label}` |
| `confidence` | **renk yok** | `%87` metin + nötr mini bar; `<0.6` → kesikli border nötr çip "Düşük güven" | — | `Güven: %{pct}` |
| kota | nötr / amber / kırmızı | banner + progress | `info` / `alert-triangle` / `lock` | `Kota: %{pct} kullanıldı` |

Kırmızı (0–10°) yalnızca `status.error` ve `quota.exceeded`. Turuncu ve mavi bandı yalnızca sentiment. Marka violet veri işaretinde asla.

## 2. Semantic token sözlüğü

### 2.1 Yüzey / metin / border

| Token | Light | Dark | Not |
|---|---|---|---|
| `surface-base` | `#FFFFFF` | `#0B0F17` | body |
| `surface-raised` | `#F7F8FA` | `#151B27` | kart, tablo satırı |
| `surface-sunken` | `#EEF0F4` | `#232B3A` | input, tablo başlığı, overlay (dark) |
| `text-primary` | `#151B27` | `#EEF0F4` | 17.24 / 15.11 |
| `text-secondary` | `#4E5A6E` | `#C7CDD8` | 6.97 / 10.80 |
| `text-tertiary` | `#6B7689` | `#98A2B3` | 4.59 / 6.69 — **daha açığı YASAK** |
| `icon-decorative` | `#98A2B3` | `#6B7689` | anlam taşıyan ikonda YASAK (2.58) |
| `border-subtle` | `#DFE3EA` | `#384357` | dekoratif |
| `border-default` | `#C7CDD8` | `#4E5A6E` | kart |
| `border-strong` | `#6B7689` | `#6B7689` | input, 3:1 gerektiren |

### 2.2 Marka

| Token | Light | Dark | Kontrast |
|---|---|---|---|
| `brand` (buton bg, beyaz metin) | `#5046D5` | `#635AE0` | 6.61 / 5.16 |
| `brand-fg` (link, aktif çip metni) | `#5046D5` | `#8F88F0` | 6.61 / 5.70 |
| `brand-subtle` (seçili satır bg) | `#EEEDFB` | `#22214A` | — |
| `focus-ring` | `#5046D5` | `#8F88F0` | 2px + 2px offset, `:focus-visible` |

### 2.3 Sentiment

| Token | Light | Dark | Kontrast |
|---|---|---|---|
| `sentiment-negative-fg` | `#9A3412` | `#FDBA74` | 6.38 (tint) / 7.49 |
| `sentiment-negative-bg` | `#FFEDD5` | `#3E302B` | |
| `sentiment-negative-fill` | `#EA580C` | `#F97316` | 3.56 / 6.15 (UI) |
| `sentiment-neutral-fg` | `#4E5A6E` | `#98A2B3` | 6.11 / 6.69 |
| `sentiment-neutral-bg` | `#EEF0F4` | `#232B3A` | |
| `sentiment-neutral-fill` | `#6B7689` | `#98A2B3` | 4.59 / 6.69 |
| `sentiment-positive-fg` | `#1E40AF` | `#93C5FD` | 7.15 / 6.97 |
| `sentiment-positive-bg` | `#DBEAFE` | `#23344D` | |
| `sentiment-positive-fill` | `#2563EB` | `#3B82F6` | 5.17 / 4.69 |

Sürekli rampa (`sentiment-scale-{n}`, heatmap/grafik; dark'ta orta koyu, uçlar açık):

| Skor | -1 | -0.66 | -0.33 | 0 | +0.33 | +0.66 | +1 |
|---|---|---|---|---|---|---|---|
| Light | `#9A3412` | `#EA580C` | `#FDBA74` | `#DFE3EA` | `#93C5FD` | `#2563EB` | `#1E40AF` |
| Dark | `#FDBA74` | `#FB923C` | `#C2410C` | `#384357` | `#1D4ED8` | `#3B82F6` | `#93C5FD` |

Etiket için backend `sentiment_label` esastır; UI eşik uydurmaz. Skor yalnızca bar uzunluğu ve metin.

### 2.4 Category

| Token | Light fg / bg / border | Dark fg / bg | Kontrast |
|---|---|---|---|
| `category-complaint` | `#86198F` / `#FAE8FF` / `#E879F9` | `#F0ABFC` / `#3B2C4D` | 7.08 / 7.22 |
| `category-praise` | `#15803D` / `#DCFCE7` / `#4ADE80` | `#86EFAC` / `#1F3E37` | 4.57 / 8.30 — **koyulaştırma serbest, açma YASAK** |
| `category-bug` | `#334155` / `#E2E8F0` / `#94A3B8` | `#CBD5E1` / `#2C3341` | 8.40 / 8.54 |
| `category-feature_request` | `#0F766E` / `#CCFBF1` / `#2DD4BF` | `#5EEAD4` / `#193C42` | 4.86 / 8.04 |

### 2.5 Status ve kota

| Token | Light text / dot | Dark text=dot | Kontrast |
|---|---|---|---|
| `status-active` | `#15803D` / `#16A34A` | `#4ADE80` | 5.02, 3.30 / 9.89 |
| `status-error` | `#B91C1C` / `#DC2626` | `#F87171` | 6.47, 4.83 / 6.23 |
| `status-paused` | `#4E5A6E` / `#6B7689` | `#98A2B3` | 6.97, 4.59 / 6.69 |
| `quota-normal` fg / bg | `#6B7689` / `#EEF0F4` | `#98A2B3` / `#232B3A` | 4.59 / 6.69 |
| `quota-warning` fg / bg (≥ %80) | `#92400E` / `#FEF3C7` | `#FCD34D` / `#3D3322` | 6.37 / 8.59 |
| `quota-exceeded` fg / bg (%100, `/402`) | `#B91C1C` / `#FEE2E2` | `#FCA5A5` / `#3C222C` | 5.30 / 7.59 |

Dark tint formülü: `mix(fill, surface-raised, 18%)`. Figma'da 18% opaklıkta fill katmanı.

### 2.6 Boyut token'ları

- Tip: `xs 12/16 · sm 14/20 · base 15/22 · lg 18/26 · xl 22/30 · 2xl 28/36 · 3xl 36/44`; Inter, `font-variant-numeric: tabular-nums` skor/KPI sütunlarında zorunlu.
- Spacing 4px taban: `1=4 2=8 3=12 4=16 5=20 6=24 8=32 10=40 12=48 16=64`. Tablo satırı 40 (yoğun) / 48 (rahat).
- Radius: `sm 4` rozet/input · `md 8` kart/buton · `lg 12` modal · `full`.
- Elevation 3 adım: `flat` (border) · `raised` `0 1px 2px rgb(11 15 23/.06)` · `overlay` `0 8px 24px rgb(11 15 23/.14)`. Dark'ta gölge yok → `surface-sunken` + `border-default`.
- z: `dropdown 10 · sticky 20 · overlay 30 · modal 40 · toast 50`.

## 3. Yasaklar

1. App kodunda primitive utility YASAK (`bg-orange-600`, `text-blue-700`, `bg-fuchsia-100` …). Yalnızca semantic utility: `bg-surface-raised`, `text-sentiment-negative-fg`. Lint regex: `\b(bg|text|border|fill|stroke)-(orange|amber|red|rose|blue|sky|cyan|teal|green|emerald|lime|fuchsia|violet|indigo|slate|gray|zinc|neutral)-\d{2,3}\b`.
2. TS/TSX içinde hex YASAK. Grafik kütüphanesi renkleri `getComputedStyle(document.documentElement).getPropertyValue('--sentiment-negative-fill')` ile CSS'ten okur.
3. Sentiment hue'su (turuncu/mavi) başka anlama verilmez. Kırmızı–yeşil sentiment YASAK.
4. Platform marka rengi/logosu yalnızca `/app/integrations` bağlantı kartında; inbox, grafik, filtre çipinde monokrom glif.
5. `confidence` ve `platform` için hue eklenmez.
6. Renk tek başına anlam taşımaz: her renkli işaretin ikonu ve metni (görünür ya da `aria-label`) vardır.
7. Yüz ikonu/emoji ile duygu gösterimi YASAK.
8. `text-tertiary`'den açık metin, `icon-decorative` ile anlam taşıyan ikon YASAK.
9. Marka violet veri işaretinde (nokta, bar, seri) YASAK.
10. UI metni hard-code YASAK — `$localize` (TR/EN).

## 4. Bileşen reçeteleri

### 4.1 `SentimentBadge`
- Input: `label: 'positive'|'neutral'|'negative'`, `score: number`.
- Şablon: `<span class="inline-flex items-center gap-1 rounded-sm px-2 h-5 text-xs font-medium bg-sentiment-{label}-bg text-sentiment-{label}-fg" [attr.aria-label]="...">` → ikon (`aria-hidden`) + metin `$localize` + `<span class="tabular-nums">{score | number:'1.2-2'}</span>`.
- Sınıf adı runtime birleştirme değil, `computed()` ile 3 sabit sınıf dizisinden seçilir (Tailwind purge güvenliği).

### 4.2 `SentimentBar`
- Input: `score` (-1..1). Host `style.--score` = `score`.
- Ortası 0 olan çift yönlü bar: track `bg-surface-sunken h-1.5 rounded-full relative`; fill `position:absolute`, `left: min(50%, 50% + var(--score) * 50%)`, `width: calc(abs(var(--score)) * 50%)`. (`abs()` Chromium ≥ 118 / Safari ≥ 15.4; desteklenmiyorsa TS'te `absScore` signal.)
- Fill rengi label'a göre `bg-sentiment-{label}-fill`. Bar `aria-hidden="true"`; yanında görünür `tabular-nums` skor metni, `aria-label` skor içerir.
- Konum tek başına yönü söyler; renk körlüğünde bu birincil kanal.

### 4.3 `CategoryChip`
- Input: `category: 'complaint'|'praise'|'bug'|'feature_request'`.
- `<span class="inline-flex items-center gap-1 rounded-sm border px-2 h-5 text-xs bg-category-{c}-bg text-category-{c}-fg border-category-{c}-border">` — **outline zorunlu**, sentiment rozetiyle şekil ayrımı bu border'dır.
- İkon sabit eşleme (§1), `aria-hidden`; metin `$localize`.
- Filtre çipi (inbox) aynı bileşen, `selectable` input ile `aria-pressed`; seçili durumda `ring-2 ring-focus-ring`, bg değişmez (bg değişirse kategori rengi bozulur).

### 4.4 `StatusDot` + `QuotaBanner`
- `StatusDot`: `<span class="inline-flex items-center gap-1.5 text-sm text-status-{s}">` + `<span class="size-2 rounded-full bg-status-{s}-dot" aria-hidden>` + ikon + metin. `error` durumunda ikon zorunlu (kırmızı tek başına yetmez); `role="status"` yalnızca canlı değişen bağlamda.
- `QuotaBanner`: `tier: 'normal'|'warning'|'exceeded'`; `normal`'da render yok. `<div role="alert" class="flex items-start gap-3 rounded-md border px-4 py-3 bg-quota-{t}-bg text-quota-{t}-fg">` + ikon + `<div role="progressbar" aria-valuenow>`. `exceeded` → `/402`'ye link `brand-fg`. Global app shell'de, inbox tablosunun DIŞINDA; satır-içi asla.

## 5. Tema mekaniği

- `<html data-theme="light|dark">`; "system" tercihinde attribute yok.
- CSS sırası: `:root {light}` → `@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {dark} }` → `:root[data-theme="dark"] {dark}`. Her token her blokta tanımlı. `body { color-scheme: light dark; background: var(--surface-base) }`.
- Tailwind v4: primitive'ler `@theme`, semantic'ler `:root` blokları, utility köprüsü `@theme inline { --color-sentiment-negative-fg: var(--sentiment-negative-fg); … }`.
- Angular `ThemeService`: `preference = signal<'light'|'dark'|'system'>(readStorage())`; `effect(() => { const p = preference(); p === 'system' ? html.removeAttribute('data-theme') : html.setAttribute('data-theme', p); writeStorage(p) })`; `resolved = computed(...)` yalnız grafik kütüphanesine tema objesi vermek için.
- FOUC: `index.html` `<head>` içinde 3 satır inline script attribute'u ilk paint öncesi basar.
- Toggle bileşeni: 3 seçenekli `radiogroup`, klavye ok tuşları, `aria-checked`.

## 6. Değişiklik prosedürü

1. Tek kaynak `frontend/src/styles/tokens.json` (DTCG). Hex'i burada değiştir; başka yerde değil.
2. `npm run tokens:build` → `tokens.css` üretir (`@theme` + üç tema bloğu).
3. `npm run tokens:check` → (a) `tokens.css` diff sıfır, (b) §2'deki tüm fg/bg çiftleri WCAG 2.1 ile yeniden hesaplanır, 4.5 (metin) / 3.0 (fill, border-strong) altı kırmızı. Çıktı rapora **gerçek sayılarla** yapıştırılır.
4. Figma: Tokens Studio "pull from GitHub" → Variables (modes: light/dark). Yön tek: **kod → Figma**. Figma'da değer denemek isteyen önce JSON PR açar.
5. Bu SKILL.md'deki tablo `tokens.json` ile senkron tutulur; ayrıştıysa `tokens.json` doğrudur, skill güncellenir.

## 7. Angular kurulunca yapılacaklar (bir sonraki tur brifingi)

- `tokens.json`'ı §2 tablolarından mekanik üret (karar yok; sonnet).
- `scripts/tokens-build.mjs` (~40 satır), `scripts/tokens-check.mjs` (kontrast + diff), `package.json` script'leri.
- `@tailwindcss/postcss` + `styles.css`'te `@import "tailwindcss"; @import "./tokens.css";`.
- `/dev/tokens` iç rotası: tüm rozet/nokta/çip/banner iki temada yan yana (Storybook yerine).
- ESLint'e §3.1 regex'i (`no-restricted-syntax` template literal + `class` attribute).

## 8. Bileşen bitiş kontrol listesi

- [ ] Primitive utility veya hex sızmadı (§3.1–3.2)
- [ ] Renkli her işaretin ikonu + metni/`aria-label`'ı var; skor metin olarak yazılı, `tabular-nums`
- [ ] `:focus-visible` halkası görünür, klavye ile ulaşılır (Tab/Enter/Space; çip için `aria-pressed`)
- [ ] `data-theme="dark"` ve `system` ile bakıldı; tint `mix(…,18%)` değerleri kullanıldı
- [ ] Sentiment = dolu, category = outline şekil kuralı korunuyor
- [ ] Platform gösterimi monokrom (integrations kartı hariç)
- [ ] UI metni `$localize`, TR+EN
- [ ] `tokens:check` çıktısı (varsa) raporda
