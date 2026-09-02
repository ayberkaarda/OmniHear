<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disposable e-mail domain blocklist
    |--------------------------------------------------------------------------
    |
    | Spec 7.1 requires registration to reject throwaway mailboxes. A request
    | whose e-mail domain (or any parent domain of it) appears here is refused
    | with `422 DISPOSABLE_EMAIL`, which is a distinct code from a plain
    | validation failure so the SPA can explain the refusal properly.
    |
    | Entries are lower-case, punycode, without a leading dot.
    |
    */

    'disposable_domains' => [
        '0-mail.com',
        '10minutemail.com',
        '20minutemail.com',
        'dispostable.com',
        'fakeinbox.com',
        'getairmail.com',
        'getnada.com',
        'guerrillamail.com',
        'inboxbear.com',
        'mailinator.com',
        'maildrop.cc',
        'mailnesia.com',
        'mintemail.com',
        'moakt.com',
        'mohmal.com',
        'sharklasers.com',
        'spam4.me',
        'temp-mail.org',
        'tempmail.com',
        'tempmailo.com',
        'throwawaymail.com',
        'trashmail.com',
        'yopmail.com',
    ],

];
