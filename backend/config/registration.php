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

    /*
    |--------------------------------------------------------------------------
    | Free consumer mailbox providers
    |--------------------------------------------------------------------------
    |
    | Spec 7.1 asks for a "disposable/free domain blocklist" — two lists, not
    | one. OmniHear is sold to companies: the corporate address *is* the signal
    | that a real business is behind the sign-up, and a Gmail address costs an
    | abuser nothing to produce by the hundred. These domains are refused with
    | the same `422 DISPOSABLE_EMAIL` code, whose message already says "use your
    | work address".
    |
    | Separate from the disposable list on purpose. A throwaway mailbox is
    | abuse; a Gmail address is a policy call, and a self-hosted deployment or a
    | freelancer-heavy market may reasonably want it off — hence the toggle. The
    | list is the major providers only: an exhaustive one would be a moving
    | target that quietly rejects legitimate customers.
    |
    | Entries are lower-case, punycode, without a leading dot.
    |
    */

    'block_free_domains' => (bool) env('REGISTRATION_BLOCK_FREE_DOMAINS', true),

    'free_domains' => [
        'aol.com',
        'gmail.com',
        'gmx.com',
        'gmx.de',
        'gmx.net',
        'googlemail.com',
        'hey.com',
        'hotmail.co.uk',
        'hotmail.com',
        'hotmail.fr',
        'icloud.com',
        'live.com',
        'mail.com',
        'mail.ru',
        'me.com',
        'msn.com',
        'naver.com',
        'outlook.com',
        'proton.me',
        'protonmail.com',
        'qq.com',
        'web.de',
        'yahoo.co.uk',
        'yahoo.com',
        'yandex.com',
        'yandex.ru',
        'zoho.com',
    ],

];
