<?php

/*
|--------------------------------------------------------------------------
| Quota notifications
|--------------------------------------------------------------------------
|
| Customer-facing copy for the soft warning of spec 7.3. Kept out of the code
| because CONTRIBUTING.md section 4 forbids hard-coded user text on either side of
| the stack; the Laravel half lives in lang/{en,tr}.
|
*/

return [

    'warning' => [
        'subject' => 'You have used :percent% of your OmniHear analysis quota',
        'greeting' => 'Hello :name,',
        'line' => ':company has used :used of :limit feedback analyses this plan period.',
        'line_remaining' => 'Once the quota is exhausted, new feedback keeps arriving and is queued for analysis - nothing is lost - but it will not be analysed until you upgrade.',
        'action' => 'Upgrade your plan',
        'salutation' => 'The OmniHear team',
    ],

];
