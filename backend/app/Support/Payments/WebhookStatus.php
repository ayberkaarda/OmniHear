<?php

namespace App\Support\Payments;

/**
 * The `status` string a webhook answers with, alongside its 200.
 *
 * These are not error codes — none of them reaches a tenant, and none belongs
 * in the ApiErrorCode catalogue. They exist so that the provider dashboard, a
 * test, and anyone reading `webhook_events` can tell "we activated a
 * subscription" apart from "we deliberately did nothing", which otherwise look
 * identical from the outside.
 */
final class WebhookStatus
{
    public const PROCESSED = 'processed';

    public const DUPLICATE_IGNORED = 'duplicate_ignored';

    public const IGNORED_UNHANDLED_TYPE = 'ignored_unhandled_type';

    public const IGNORED_MALFORMED = 'ignored_malformed';

    public const IGNORED_UNPAID = 'ignored_unpaid';

    public const IGNORED_UNKNOWN_TENANT = 'ignored_unknown_tenant';

    public const IGNORED_UNKNOWN_PLAN = 'ignored_unknown_plan';
}
