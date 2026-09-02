<?php

/*
|--------------------------------------------------------------------------
| Successful-response messages
|--------------------------------------------------------------------------
|
| A separate file from errors.php so that neither one overrides a framework
| namespace (auth.php, passwords.php, validation.php).
|
*/

return [

    // Deliberately does not confirm whether the address exists.
    'password_reset_link_sent' => 'If that email address is registered, a password reset link is on its way.',
    'password_reset' => 'Your password has been reset.',
    'verification_link_sent' => 'A verification link has been sent to your email address.',
    'verification_link_invalid' => 'This verification link is invalid or has expired.',
    'account_erased' => 'Your account and all associated data have been deleted.',

];
