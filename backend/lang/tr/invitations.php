<?php

return [

    'mail' => [
        'subject' => 'OmniHear üzerinde :company ekibine davet edildiniz',
        'greeting' => 'Merhaba,',
        'line' => 'OmniHear üzerinde :company ekibine katılmaya davet edildiniz.',
        'line_from' => ':inviter sizi OmniHear üzerinde :company ekibine katılmaya davet etti.',
        'role' => 'Ekibe :role olarak katılacaksınız.',
        'action' => 'Daveti kabul et',
        'expiry' => 'Bu davetin süresi :days gün içinde doluyor.',
        'ignore' => 'Böyle bir davet beklemiyorsanız bu e-postayı yok sayabilirsiniz.',
        'salutation' => 'OmniHear ekibi',
    ],

    'roles' => [
        'owner' => 'sahip',
        'admin' => 'yönetici',
        'member' => 'üye',
    ],

    'email_taken' => 'Bu e-posta adresi için zaten bir hesap var. Bunun yerine giriş yapın.',

];
