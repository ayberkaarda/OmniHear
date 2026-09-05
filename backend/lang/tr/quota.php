<?php

/*
|--------------------------------------------------------------------------
| Kota bildirimleri
|--------------------------------------------------------------------------
|
| Spec 7.3'teki yumusak uyarinin kullaniciya donuk metinleri. Kod icine
| gomulmez: CONTRIBUTING.md 4. bolum her iki tarafta da hard-code UI metnini
| yasaklar, Laravel tarafinin karsiligi lang/{en,tr} dizinidir.
|
*/

return [

    'warning' => [
        'subject' => 'OmniHear analiz kotanizin %:percent kadarini kullandiniz',
        'greeting' => 'Merhaba :name,',
        'line' => ':company bu plan doneminde :limit analizin :used tanesini kullandi.',
        'line_remaining' => 'Kota dolduktan sonra yeni geri bildirimler gelmeye devam eder ve analiz kuyrugunda birikir - hicbiri kaybolmaz - ancak planinizi yukseltene kadar analiz edilmez.',
        'action' => 'Plani yukselt',
        'salutation' => 'OmniHear ekibi',
    ],

];
