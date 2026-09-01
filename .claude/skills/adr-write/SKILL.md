---
name: adr-write
description: OmniHear için mimari bir karar kaydı (ADR) yazarken kullanılır. Tetikleyiciler ADR, mimari karar, karar kaydı, architecture decision, neden X seçtik, architecture decision record.
---

# ADR Write

Mimari bir kararı (teknoloji seçimi, veri modeli değişikliği, entegrasyon stratejisi vb.) gelecekte referans alınabilecek şekilde kayıt altına almak için kullanılır.

## Ne zaman yükle

Kullanıcı "bunu neden böyle yaptık", "ADR yaz", "mimari karar kaydı" dediğinde, ya da önemli bir tasarım kararı (ör. hangi kuyruk sürücüsü, hangi ödeme sağlayıcı sırası, kota sayacının atomiklik stratejisi) alınıp gerekçelendirilmesi gerektiğinde.

## Adımlar

### 1. Dosya yolu ve numaralandırma

```
docs/adr/NNNN-<kebab-baslik>.md
```

`NNNN` dört haneli, sıralı bir numaradır. Mevcut en yüksek numarayı bul, bir artır:

```bash
ls docs/adr/ | grep -E '^[0-9]{4}-' | sort -r | head -1
```

Klasör yoksa oluştur. Başlık kebab-case ve İngilizce olmalı (kod/teknik terim kuralı): `0007-quota-counter-atomic-update.md`.

### 2. Şablon

```markdown
# NNNN. <Başlık>

## Durum

Önerildi

<!-- Alternatifler: Kabul | Reddedildi | Yerini aldı: ADR-NNNN -->

## Bağlam

<Hangi problem çözülüyor? Hangi kısıtlar var (performans, güvenlik, mevcut şema,
takım kapasitesi)? Neden şu an karar vermek gerekiyor?>

## Karar

<Ne karar verildi — tek cümlede net ifade. Ardından uygulama detayı.>

## Alternatifler

### Alternatif A: <isim>

<Ne olduğu.> Elendi çünkü <somut gerekçe — performans ölçümü, maliyet, uyumsuzluk vb.>.

### Alternatif B: <isim>

<Ne olduğu.> Elendi çünkü <somut gerekçe>.

## Sonuçlar

**Olumlu:**
- <kazanım 1>
- <kazanım 2>

**Olumsuz / kabul edilen borç:**
- <trade-off 1 — neden kabul edildiği>
- <trade-off 2>

## İlgili spec bölümü

<docs/ içindeki ilgili doküman + bölüm referansı, örn. docs/QUOTA-DESIGN.md §3>
```

### 3. Durum alanı kuralları

- **Önerildi:** Henüz onaylanmadı, tartışmaya açık.
- **Kabul:** Uygulanmış veya uygulanması onaylanmış.
- **Reddedildi:** Değerlendirildi ama uygulanmayacak — gerekçesi "Bağlam"/"Sonuçlar"da kalır, silinmez.
- **Yerini aldı: ADR-NNNN:** Daha yeni bir karar bunun yerine geçti; eski ADR **silinmez**, durumu güncellenir ve yeni ADR'ye referans verir. Yeni ADR de eskisine geriye dönük referans verir.

### 4. Alternatifler bölümü zorunlu

Her ADR en az bir gerçek alternatif içerir ve her biri **neden elendiğini somut gerekçeyle** açıklar ("daha kötü" gibi belirsiz ifadeler yeterli değildir — "p95 latency'yi 1.2s'ye çıkarıyor" gibi ölçülebilir/somut bir gerekçe olmalı).

### 5. Yazma dili

Doküman içi teknik terimler, kod parçaları, dosya/tablo adları İngilizce kalır; düzyazı (Bağlam, Karar, Sonuçlar açıklamaları) Türkçe yazılır — CLAUDE.md §0.6 ile tutarlı.

### 6. Sözleşme dokümanlarıyla ilişki

ADR bir kararın **gerekçesini** taşır; SYNCDESKTOP/spec dokümanları gibi bağlayıcı bir sözleşme değildir. Bir ADR var olan bir spec kararıyla çelişiyorsa, spec kazanır — ADR'ye "spec ile çelişiyor, spec'in güncellenmesi önerilir" notu düşülür, spec sessizce değiştirilmez.

## Zorunlu testler

ADR bir doküman olduğu için otomatik test kapsamına girmez. Bunun yerine **yazım kontrol listesi**:
- [ ] Dosya adı `NNNN-<kebab-baslik>.md` formatında ve numara mevcut en yükseği bir artırıyor.
- [ ] "Durum" alanı dört değerden biri (Önerildi/Kabul/Reddedildi/Yerini aldı) ve doğru yazılmış.
- [ ] En az bir gerçek alternatif var, her biri somut gerekçeyle elenmiş.
- [ ] "Sonuçlar" hem olumlu hem olumsuz/borç maddesi içeriyor — yalnızca artıları listelemek yasak.
- [ ] "İlgili spec bölümü" referansı var ve gerçekten var olan bir dosya/bölüme işaret ediyor.
- [ ] Kararın kod/config değişikliği gerektiriyorsa, bu ADR'nin kendisi kod yazmaz — ilgili sub-agent'a ayrı görev olarak devredilir.
