<?php

use App\Support\Connectors\AppStoreConnector;
use App\Support\Connectors\EmailConnector;
use App\Support\Connectors\FixtureConnector;
use App\Support\Connectors\GooglePlayConnector;
use App\Support\Connectors\TrustpilotConnector;
use App\Support\Connectors\ZendeskConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | Fixture root
    |--------------------------------------------------------------------------
    |
    | Where FixtureConnector reads its pages from, and where the recorded
    | platform responses live.
    |
    | It is under tests/ rather than under the repository's contracts/ directory
    | for one concrete reason: the dev compose stack bind-mounts only
    | `../backend` into the backend container (infra/docker-compose.dev.yml), so
    | `contracts/` does not exist at run time and nothing inside the container
    | can read it. `contracts/fixtures/platforms/appstore/` keeps the provenance
    | copy of the recorded App Store responses; this is the copy the code and
    | the suite actually load. Override the path rather than moving the files if
    | a mount is ever added.
    |
    */

    'fixtures_path' => env('CONNECTOR_FIXTURES_PATH', base_path('tests/Fixtures/platforms')),

    /*
    |--------------------------------------------------------------------------
    | Sync lock
    |--------------------------------------------------------------------------
    |
    | How long a single integration's sync may hold its lock before another run
    | is allowed to start. Long enough to cover the worst paged run plus queue
    | backoff, short enough that a worker killed mid-run does not wedge the
    | integration until someone notices. While the lock is held,
    | POST /integrations/{id}/sync answers 409 SYNC_IN_PROGRESS.
    |
    */

    'sync_lock_ttl' => (int) env('CONNECTOR_SYNC_LOCK_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Spec 6.1: the scheduler dispatches FetchFeedbackJob for active
    | integrations every five minutes.
    |
    */

    'schedule' => [
        'chunk' => (int) env('CONNECTOR_SCHEDULE_CHUNK', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platforms
    |--------------------------------------------------------------------------
    |
    | Only the platforms listed here may be created through the API. The schema
    | allows more values (backend-core.md section 1) because later phases add
    | them; an integration for a platform with no connector would sit in the
    | scheduler failing forever, so the create endpoint refuses it up front.
    |
    | `rate_limit` is the per-platform throttle spec 6.1 asks for. It is keyed
    | by platform, not by integration: the limit belongs to the third party, and
    | every tenant syncing the same platform shares it.
    |
    */

    'platforms' => [

        'fixture' => [
            'connector' => FixtureConnector::class,
            'required_settings' => [],
            // Accepted but not mandatory. Declared so that
            // GET /integrations/platforms can publish the *whole* form and not
            // only its required half; StoreIntegrationRequest already validates
            // this key.
            'optional_settings' => ['fixture_set'],
            'max_pages_per_run' => 10,
            'max_consecutive_empty_pages' => 3,
            'rate_limit' => ['max_attempts' => 600, 'decay_seconds' => 60],
            'retry_after' => 5,
        ],

        'appstore' => [
            'connector' => AppStoreConnector::class,
            'base_url' => env('APPSTORE_RSS_BASE_URL', 'https://itunes.apple.com'),
            'required_settings' => ['app_id', 'country'],
            'optional_settings' => [],
            // Hard platform ceiling, measured 2026-09-02: page=11 answers HTTP
            // 400 "CustomerReviews RSS page depth is limited to 10".
            'max_pages_per_run' => 10,
            // The feed returns empty pages intermittently, so a short streak is
            // normal; three in a row means something is actually wrong.
            'max_consecutive_empty_pages' => 3,
            'timeout' => (int) env('APPSTORE_TIMEOUT', 15),
            'rate_limit' => ['max_attempts' => 20, 'decay_seconds' => 60],
            'retry_after' => 60,
        ],

        'zendesk' => [
            'connector' => ZendeskConnector::class,
            // The subdomain is substituted in ConnectorFactory, which whitelists
            // it first: it is part of the host, so a value carrying `/`, `@` or
            // `:` would point every authenticated request somewhere else.
            'base_url' => env('ZENDESK_BASE_URL', 'https://{subdomain}.zendesk.com'),
            'required_settings' => ['subdomain'],
            'optional_settings' => [],
            // The first connector with credentials. `email` plus `api_token`
            // are Zendesk's API-token scheme; both are stored encrypted and
            // neither is ever read back out (invariant I5).
            'required_credentials' => ['email', 'api_token'],
            // A runaway-loop ceiling, not a platform limit: the incremental
            // export has no depth cap, it ends when end_of_stream says so. At
            // up to 1000 tickets a page this still lets one run cover 20k
            // tickets, and a run cut short here resumes from the stored cursor.
            'max_pages_per_run' => 20,
            'max_consecutive_empty_pages' => 3,
            'timeout' => (int) env('ZENDESK_TIMEOUT', 30),
            // Documented as its own budget for the incremental export
            // endpoints, well below the account-wide API limit.
            'rate_limit' => ['max_attempts' => 10, 'decay_seconds' => 60],
            'retry_after' => 60,
            // How far back the very first run reaches when there is no cursor
            // yet. Everything after that is driven by Zendesk's own cursor.
            'initial_lookback_days' => (int) env('ZENDESK_INITIAL_LOOKBACK_DAYS', 30),
            // Zendesk refuses a start_time too close to now, so the first
            // request is held this far behind the clock.
            'start_time_lag_seconds' => (int) env('ZENDESK_START_TIME_LAG', 300),
        ],

        'googleplay' => [
            'connector' => GooglePlayConnector::class,
            'base_url' => env('GOOGLEPLAY_BASE_URL', 'https://androidpublisher.googleapis.com'),
            // Where the service-account JWT is exchanged for an access token.
            // Its own key rather than a constant, because it is a different
            // host from the API and both are overridden together in tests.
            'token_url' => env('GOOGLEPLAY_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            'required_settings' => ['package_name'],
            'optional_settings' => [],
            // A service account, not an API token — the first credential in
            // this file that is an asymmetric private key. GooglePlayAccessToken
            // signs with it and nothing else ever touches it (invariant I5).
            'required_credentials' => ['client_email', 'private_key'],
            // `reviews.list` only answers for roughly the last seven days, so a
            // run is bounded by the platform rather than by this number; it is
            // a runaway-loop ceiling. At 100 per page it still covers 1000
            // reviews in one pass, which is far past a week for any app this
            // product is aimed at.
            'max_pages_per_run' => 10,
            'max_consecutive_empty_pages' => 3,
            // An app with nothing in the window answers `{}` every single run.
            // That is a healthy integration, not a fault, which is why the
            // connector reads an absent `reviews` key as an empty page.
            'max_results' => (int) env('GOOGLEPLAY_MAX_RESULTS', 100),
            'timeout' => (int) env('GOOGLEPLAY_TIMEOUT', 30),
            // Google publishes a per-project quota rather than a per-endpoint
            // one; this is a deliberately conservative share of it.
            'rate_limit' => ['max_attempts' => 20, 'decay_seconds' => 60],
            'retry_after' => 60,
        ],

        'trustpilot' => [
            'connector' => TrustpilotConnector::class,
            'base_url' => env('TRUSTPILOT_BASE_URL', 'https://api.trustpilot.com'),
            // Whitelisted in the connector's constructor (24 hex characters),
            // not here: it is substituted into the URL path, and the factory
            // does not duplicate a rule the connector already refuses on.
            'required_settings' => ['business_unit_id'],
            'optional_settings' => [],
            // Travels in the `apikey` header and never in the query string.
            'required_credentials' => ['api_key'],
            // Page-based and newest-first, with no depth cap of its own, so
            // this is a runaway-loop ceiling like Zendesk's. 20 x 100 covers
            // 2000 reviews in one run and a run cut short resumes from the
            // stored watermark.
            'max_pages_per_run' => 20,
            'max_consecutive_empty_pages' => 3,
            'per_page' => (int) env('TRUSTPILOT_PER_PAGE', 100),
            'timeout' => (int) env('TRUSTPILOT_TIMEOUT', 30),
            'rate_limit' => ['max_attempts' => 30, 'decay_seconds' => 60],
            'retry_after' => 60,
        ],

        'email' => [
            'connector' => EmailConnector::class,
            // A shared mailbox over JMAP (RFC 8620/8621), not a vendor API — the
            // session URL, bearer token and the mailbox name to read all come
            // from the user, so all three are credentials rather than settings
            // (docs/contracts/w11-email-connector.md).
            'required_settings' => [],
            'optional_settings' => [],
            'required_credentials' => ['session_url', 'api_token', 'mailbox'],
            // Email/changes has no depth cap of its own — it ends when
            // hasMoreChanges says so — so this is a runaway-loop ceiling like
            // Zendesk's and Trustpilot's, not a platform limit.
            'max_pages_per_run' => 20,
            'max_consecutive_empty_pages' => 3,
            // One page is one Email/query or Email/changes chained into one
            // Email/get via a result reference (RFC 8620 section 3.7), capped
            // again inside the connector by the session's own maxObjectsInGet.
            'page_size' => (int) env('EMAIL_PAGE_SIZE', 50),
            // Bounds Email/get's maxBodyValueBytes. Left unbounded, a single
            // message's quoted thread history could put megabytes into
            // raw_payload; this is generous for a feedback email while keeping
            // that column sane.
            'max_body_bytes' => (int) env('EMAIL_MAX_BODY_BYTES', 20000),
            'timeout' => (int) env('EMAIL_TIMEOUT', 30),
            // No public JMAP rate limit to read off a spec — this is a
            // conservative default in the same range as the other credentialed
            // connectors above.
            'rate_limit' => ['max_attempts' => 30, 'decay_seconds' => 60],
            'retry_after' => 60,
            // How far back the very first run reaches when there is no stored
            // token yet. Mirrors Zendesk's initial_lookback_days for the same
            // reason: without it a mailbox holding years of mail spends years
            // of analysis quota on its first sync.
            'initial_lookback_days' => (int) env('EMAIL_INITIAL_LOOKBACK_DAYS', 30),
        ],

    ],

];
