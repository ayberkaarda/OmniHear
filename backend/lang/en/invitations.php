<?php

/*
|--------------------------------------------------------------------------
| Team invitations
|--------------------------------------------------------------------------
|
| Customer-facing copy for the invitation mail and for the one validation
| message the accept endpoint can raise. Kept out of the code because
| CLAUDE.md section 6 forbids hard-coded user text on either side of the
| stack; the Laravel half lives in lang/{en,tr}.
|
*/

return [

    'mail' => [
        'subject' => 'You have been invited to :company on OmniHear',
        'greeting' => 'Hello,',
        'line' => 'You have been invited to join :company on OmniHear.',
        'line_from' => ':inviter has invited you to join :company on OmniHear.',
        'role' => 'You will join as :role.',
        'action' => 'Accept the invitation',
        'expiry' => 'This invitation expires in :days days.',
        'ignore' => 'If you were not expecting this invitation you can safely ignore this email.',
        'salutation' => 'The OmniHear team',
    ],

    'roles' => [
        'owner' => 'an owner',
        'admin' => 'an administrator',
        'member' => 'a member',
    ],

    // The invitation is deliberately left open when this fires: the invitee
    // already having an account is a different problem from a bad token.
    'email_taken' => 'An account already exists for this email address. Sign in instead.',

];
