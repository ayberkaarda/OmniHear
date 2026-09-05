<?php

/*
|--------------------------------------------------------------------------
| API error catalogue
|--------------------------------------------------------------------------
|
| One entry per code in docs/contracts/http-api-v1.md section 2. These strings
| are developer-facing: the SPA renders its own translation of `code` and only
| falls back to `message`. Adding a code here means adding it to lang/tr as well.
|
*/

return [

    'VALIDATION_ERROR' => 'The given data was invalid.',
    'INVALID_CREDENTIALS' => 'These credentials do not match our records.',
    'UNAUTHENTICATED' => 'Authentication is required to access this resource.',
    'EMAIL_NOT_VERIFIED' => 'Your email address is not verified.',
    'FORBIDDEN' => 'You are not allowed to perform this action.',
    'NOT_FOUND' => 'The requested resource was not found.',
    'QUOTA_EXCEEDED' => 'Your analysis quota is exhausted. Upgrade your plan to continue.',
    'TOO_MANY_REQUESTS' => 'Too many requests. Please slow down and try again shortly.',
    'DISPOSABLE_EMAIL' => 'Disposable email addresses are not accepted. Use your work address.',
    'SERVER_ERROR' => 'Something went wrong on our side.',

    // The F4-F7 phase group (F4 ingestion, F5 analysis and quota, F6/F7 payments).
    'INTEGRATION_UNAVAILABLE' => 'The connected platform is not responding. We will retry automatically.',
    'INTEGRATION_INVALID_CREDENTIALS' => 'The credentials for this integration were rejected by the platform.',
    'SYNC_IN_PROGRESS' => 'A sync is already running for this integration.',
    'AI_SERVICE_UNAVAILABLE' => 'Analysis is temporarily unavailable. Your feedback is queued and nothing is lost.',
    'INVALID_WEBHOOK_SIGNATURE' => 'The webhook signature could not be verified.',
    'PAYMENT_PROVIDER_ERROR' => 'The payment provider returned an error. No charge was made.',

    // W10 — two-factor authentication.
    'TWO_FACTOR_CODE_INVALID' => 'That code is not valid. Check your authenticator app and try again.',
    'TWO_FACTOR_ALREADY_ENABLED' => 'Two-factor authentication is already enabled on this account.',
    'TWO_FACTOR_NOT_ENABLED' => 'Two-factor authentication is not enabled on this account.',

];
