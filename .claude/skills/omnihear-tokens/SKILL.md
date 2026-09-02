---
name: omnihear-tokens
description: OmniHear renk/token kuralları — sentiment (duygu) kırmızı-yeşil skalası ve açıklık ayrımı zorunluluğu, category (kategori) rozetleri, platform/kaynak ikonları, integration status, quota/402 renkleri, light/dark semantic token sözlüğü, .dark tema mekaniği ve Tailwind eşlemesi. Yeni Angular bileşeni, badge/rozet, chip/çip, KPI kartı, inbox satırı, filtre, grafik yazarken veya tokens.json / tokens.css / tailwind.config.js değiştirirken yükle. Triggers: sentiment color, duygu rengi, kategori rozeti, category badge, token, tema, dark mode, koyu tema, platform ikonu, quota banner, paywall, 402, kontrast, WCAG, renk körlüğü, colorblind, deuteranopi, tailwind theme, ThemeService, grafik rengi.
---

# OmniHear Tokens

Bu skill **kural** verir; değer vermez. Değerlerin tek kaynağı `frontend/src/styles/tokens.json`.

Katmanlar:
- **Tasarım dosyası** (`OmniHear Foundations.dc.html`, Claude Design) = niyet
- **`tokens.json`** = gerçek, tek kaynak
- **Bu skill** = kurallar + prosedür
- **`tokens.css`** = üretilmiş çıktı, **elle düzenlenmez**

Grafik türü/legend/tooltip metodolojisi → `dataviz`. Görsel yön, tipografi kişiliği → `frontend-design`.

## 1. Karar tablosu — veri boyutu → kanal

Renk körlüğünde 2 kanal kalır (mavi↔sarı ekseni + luminans). Her boyut **ikon + metin + konum** ile yedeklenir; renk tek başına anlam taşımaz.

| Boyut | Renk | Şekil | İkon (Lucide) | Yedek kanal |
|---|---|---|---|---|
| `sentiment_label` / `sentiment_score` | kırmızı (neg) ↔ yeşil (poz), nötr gri | dolu fill / rozet | `frown` · `meh` · `smile` | ikon + etiket + skor metni |
| `category` | amber · teal · rose · violet | **outline** rozet + tint | `megaphone` · `heart` · `bug` · `lightbulb` | ikon + etiket |
| `platform` / kaynak | **renk yok** — `--source-*` | monokrom glif | platform glifi | metin |
| `integrations.status` | success / error / paused | nokta + metin | `check-circle` · `x-circle` · `pause-circle` | ikon + metin |
| `confidence` | **renk yok** | `%87` metin + nötr bar | — | sayı |
| kota | ok / warning / exceeded | banner + progress | `info` · `alert-triangle` · `lock` | ikon + yüzde metni |

## 2. Değerler — burada değil

Yedi anlam renginin `-text` / `-bg` / `-border` / `-fill` değerleri, yüzeyler, metin, marka, status, kota, tipografi, radius ve z-index: **`frontend/src/styles/tokens.json`**.

Bu dosyadaki tabloyu çoğaltma. Bir değeri öğrenmek için `tokens.json`'ı oku; değiştirmek için §5 prosedürünü izle.

## 3. Sentiment: kırmızı-yeşil, ama açıklık ayrımı zorunlu

Kullanıcının brief'i yeşil=olumlu / kırmızı=olumsuz aile istiyor ve CX araçlarında bu evrensel konvansiyon. Kırmızı-yeşil **yasak değil.**

**Ama:** hue kaybolduğunda geriye ayırt edici kanal kalmalı. Ölçüm (Viénot 1999 + OKLab dE):

- Eşit algısal açıklıktaki kırmızı/yeşil çifti deuteranopide **dE ≈ 0.03** — pratikte aynı renk.
- Açıklığı ayrılmış çift **dE ≈ 0.21** — yedi kat.

Bu yüzden sentiment `-fill` üçlüsü **açıklık sırasına dizilir**: negative koyu → neutral orta → positive açık. `tokens-check` bunu zorlar.

**Rozet `-text`/`-bg`/`-border`'da bu kural yoktur** — orada ikon, etiket ve skor üç yedek kanal sağlıyor, pastel tutarlılığı önceliklidir. Bu bilinçli bir ödünleşmedir: rozet metin çiftinin deutan ayrımı düşüktür ve renk orada tek başına anlam taşımadığı için kabul edilir.

**`-fill` metin rengi olarak kullanılamaz.** KPI delta metni `-text` token'ıyla yazılır; `-fill` yalnız dolu şekiller içindir (bar, nokta, gauge).

## 4. Yasaklar

1. App kodunda **ham hex YASAK**. `#` ile başlayan renk değeri yalnız `tokens.json`'da bulunur.
2. Primitive Tailwind utility YASAK (`bg-red-600`, `text-green-700` …). Yalnız semantic: `bg-surface`, `text-sentiment-negative-text`.
3. TS içinde renk sabiti YASAK. Grafik kütüphanesi renkleri `getComputedStyle(document.documentElement).getPropertyValue('--sentiment-negative-fill')` ile CSS'ten okunur.
4. Renk tek başına anlam taşımaz: her renkli işaretin ikonu ve metni (görünür ya da `aria-label`) vardır.
5. **Emoji ile duygu gösterimi YASAK.** Lucide `smile`/`meh`/`frown` SVG'leri kullanılır (bunlar ikon, emoji değil).
6. Platform marka rengi/logosu yalnız `/app/integrations` bağlantı kartında; inbox, grafik ve filtrede monokrom.
7. `-fill` metin olarak, `-text` dolgu olarak kullanılamaz.
8. UI metni hard-code YASAK — `$localize` (TR/EN).
9. Sentiment hue'ları başka bir anlama verilmez; kategori paleti kırmızı ve yeşilden uzak durur.

## 5. Değişiklik prosedürü

1. `frontend/src/styles/tokens.json` düzenle — **başka hiçbir yerde değil**.
2. `npm run tokens:build` → `tokens.css` üretir.
3. `npm run tokens:check` → dört bölüm çalışır, çıktısı **gerçek sayılarla** rapora girer.
4. Fail varsa değeri düzelt; **eşiği düşürme**.

### `tokens:check` neyi zorlar

**(d) Kalibrasyon — önce çalışır, başarısızsa diğerleri hiç koşmaz.** Simülatör kendini sınar: saf kırmızı/yeşil deutan'da çökmeli (dE < 0.25), mavi/sarı korunmalı (> 0.60), mavi mavi kalmalı, aynı renk 0 vermeli.

> Bu bölüm bir olaydan doğdu: kalibre edilmemiş bir renk körlüğü simülasyonu yanlış sayı üretti ve az kalsın yanlış bir palet kararına yol açıyordu. Kalibrasyon assert'lerini kaldırma.

**(a)** `tokens.css`, `tokens.json`'dan yeniden üretildiğinde byte-eş olmalı.

**(b) WCAG:** her anlam renginin `-text`/`-bg` çifti ≥ 4.5 · metin katmanları ≥ 4.5 · her `-fill` yüzeyde ≥ 3.0 · `ring-focus` ≥ 3.0.

**(c) Renk körlüğü** (Viénot 1999 LMS + OKLab dE):
- sentiment negative/positive `-fill`: deutan ≥ **0.15**, protan ≥ **0.12**, OKLCH ΔL ≥ **0.15**
- komşu sentiment fill çiftleri: deutan ≥ **0.12**
- kategori fill ikilileri: deutan ≥ **0.10** — **sağlanmazsa fail değil UYARI**, telafisi desen dolgusu
- yedi `-fill` hue'su arasında ≥ **30°**

## 6. Grafiklerde renk tek kanal — yedek zorunlu

Rozette ikon+etiket var, grafikte yok. Bu yüzden:

- **Sentiment stacked bar:** sabit yığın sırası negative (taban) → neutral → positive, legend aynı sırada; segment ≥ 24 px ise doğrudan yüzde etiketi.
- **Kategori serileri:** `praise`/`bug` çifti deutan eşiğinin altında (`tokens-check` uyarır) → kategori grafiklerinde **desen dolgusu (decal) zorunlu**. Sentiment grafiğinde decal yok, üçlü zaten geçiyor.
- Her grafiğin yanında "tabloya geç" bağlantısı.

## 7. Tema mekaniği — `.dark` sınıfı

- `darkMode: 'class'`. **`prefers-color-scheme` CSS bloğu YOK** — sistem tercihi TS'te sınıfa çözülür.
- `ThemeService` (`core/theme/`): `preference` signal (`light`/`dark`/`system`, localStorage'da), `resolved` computed, `effect()` ile `documentElement.classList.toggle('dark')`. `system` seçiliyken `matchMedia` değişimi canlı dinlenir. `localStorage` erişimi try/catch'li (gizli sekmede patlar).
- FOUC: `index.html` `<head>`'inde inline script ilk paint öncesi sınıfı basar; `ThemeService` ile **aynı anahtarı ve aynı çözümleme mantığını** kullanır — ikisi ayrışırsa tema açılışta zıplar.
- Font: **IBM Plex Sans** (400/500/600) + **IBM Plex Mono** (400/500). Sayı sütunlarında `tabular-nums` zorunlu.

## 8. Bileşen bitiş kontrol listesi

- [ ] Ham hex veya primitive utility sızmadı (`grep -rn "#[0-9a-fA-F]\{3,6\}" src/app/`)
- [ ] Renkli her işaretin ikonu + metni/`aria-label`'ı var
- [ ] Skor metin olarak yazılı, `tabular-nums`
- [ ] `:focus-visible` halkası görünür, klavye ile ulaşılabilir
- [ ] `.dark` sınıfı eklenerek ve `system` tercihiyle bakıldı
- [ ] Sentiment = dolu, category = outline şekil kuralı korunuyor
- [ ] Grafikse: yedek kanal var (yığın sırası / etiket / decal)
- [ ] UI metni `$localize`, TR+EN
- [ ] `npm run tokens:check` çıktısı raporda
