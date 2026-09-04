<?php

/*
|--------------------------------------------------------------------------
| API error catalogue (tr)
|--------------------------------------------------------------------------
|
| Mirrors lang/en/errors.php key for key. A missing key here silently falls
| back to English, so the two files are kept in step.
|
*/

return [

    'VALIDATION_ERROR' => 'Gönderilen veriler geçersiz.',
    'INVALID_CREDENTIALS' => 'Bu kimlik bilgileri kayıtlarımızla eşleşmiyor.',
    'UNAUTHENTICATED' => 'Bu kaynağa erişmek için oturum açmanız gerekiyor.',
    'EMAIL_NOT_VERIFIED' => 'E-posta adresiniz doğrulanmamış.',
    'FORBIDDEN' => 'Bu işlemi yapmaya yetkiniz yok.',
    'NOT_FOUND' => 'İstenen kayıt bulunamadı.',
    'QUOTA_EXCEEDED' => 'Analiz kotanız doldu. Devam etmek için planınızı yükseltin.',
    'TOO_MANY_REQUESTS' => 'Çok fazla istek gönderildi. Lütfen biraz bekleyip tekrar deneyin.',
    'DISPOSABLE_EMAIL' => 'Geçici e-posta adresleri kabul edilmiyor. Lütfen kurumsal adresinizi kullanın.',
    'SERVER_ERROR' => 'Sunucu tarafında bir hata oluştu.',

    // Wave 2 (F4 ingestion, F5 analysis and quota, F6/F7 payments).
    'INTEGRATION_UNAVAILABLE' => 'Bağlı platform yanıt vermiyor. Otomatik olarak yeniden denenecek.',
    'INTEGRATION_INVALID_CREDENTIALS' => 'Bu entegrasyonun kimlik bilgileri platform tarafından reddedildi.',
    'SYNC_IN_PROGRESS' => 'Bu entegrasyon için zaten bir senkronizasyon çalışıyor.',
    'AI_SERVICE_UNAVAILABLE' => 'Analiz geçici olarak kullanılamıyor. Yorumlarınız kuyrukta bekliyor, hiçbiri kaybolmadı.',
    'INVALID_WEBHOOK_SIGNATURE' => 'Webhook imzası doğrulanamadı.',
    'PAYMENT_PROVIDER_ERROR' => 'Ödeme sağlayıcısı hata döndürdü. Herhangi bir tahsilat yapılmadı.',

    // W10 — two-factor authentication.
    'TWO_FACTOR_CODE_INVALID' => 'Bu kod geçerli değil. Doğrulama uygulamanızı kontrol edip tekrar deneyin.',
    'TWO_FACTOR_ALREADY_ENABLED' => 'Bu hesapta iki adımlı doğrulama zaten etkin.',
    'TWO_FACTOR_NOT_ENABLED' => 'Bu hesapta iki adımlı doğrulama etkin değil.',

];
