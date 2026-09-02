# Zendesk incremental-export fixtures — provenance

**Nothing here was captured. Every byte is synthetic.**

There is no Zendesk account behind this project. These files were written from
Zendesk's published Support API documentation so that `ZendeskConnector` and the
whole ingestion path can be exercised with `Http::fake()`. They contain no real
customer, no real agent, no real ticket and no real credential — the requester
names are `requester-01` … `requester-05` and every address is on
`example.invalid`, a domain RFC 2606 reserves so it can never resolve.

That is a policy, not an accident. It is the same rule the App Store fixtures
were rewritten to satisfy (decision D-06, `docs/PROGRESS.md`): **no real
person's data enters a fixture.**

## Verified against documentation vs inferred

Because none of this was measured against a live account, the distinction
matters more here than for a captured fixture. Anything in the second table is
a place to look first if a real account behaves differently.

### Taken from the documentation

| what | detail |
|---|---|
| endpoint | `GET /api/v2/incremental/tickets/cursor.json` — the cursor-based incremental export, which Zendesk documents as the recommended form and the successor to the time-based one |
| first call | `?start_time=<unix seconds>` |
| later calls | `?cursor=<after_cursor>` |
| response envelope | `tickets`, `after_url`, `after_cursor`, `before_url`, `before_cursor`, `end_of_stream` |
| stop condition | `end_of_stream: true` means the caller has caught up |
| page size | up to 1,000 tickets per response |
| authentication | HTTP Basic with username `{email}/token` and password `{api_token}` |
| rate limiting | HTTP **429**; the incremental-export endpoints carry their own, much smaller budget than the account-wide API limit |
| `start_time` floor | a `start_time` too close to now is refused with **422** |
| auth failure | **401**, body `{"error": "Couldn't authenticate you"}` |
| permission failure | **403** when the credentials are valid but the agent may not run exports |
| ticket field set | `id`, `url`, `external_id`, `via`, `created_at`, `updated_at`, `generated_timestamp`, `type`, `subject`, `raw_subject`, `description`, `priority`, `status`, `recipient`, `requester_id`, `submitter_id`, `assignee_id`, `organization_id`, `group_id`, `collaborator_ids`, `follower_ids`, `email_cc_ids`, `forum_topic_id`, `problem_id`, `has_incidents`, `is_public`, `due_at`, `tags`, `custom_fields`, `satisfaction_rating`, `sharing_agreement_ids`, `fields`, `followup_ids`, `ticket_form_id`, `brand_id`, `allow_channelback`, `allow_attachments`, `from_messaging_channel` |
| `description` | the customer's first message on the ticket |
| `via` | `{"channel": ..., "source": {"from": {...}, "to": {...}, "rel": ...}}`; on an email ticket `source.from` carries the requester's `address` and `name` |
| `satisfaction_rating` | `{"id": ..., "score": "good"\|"bad", "comment": ...}`, or `{"score": "unoffered"}` when CSAT was never asked for |
| deleted tickets | keep appearing in incremental exports, with `status: "deleted"` |
| ordering | by when a ticket last changed, **not** by `created_at` |
| timestamps | ISO 8601 in UTC with a `Z` suffix |

### Inferred, and worth re-checking against a live account

| what | why it is a guess |
|---|---|
| the exact wording of the 429 body (`{"error": "RateLimited", "description": "Number of allowed API requests per minute exceeded"}`) | the status code is what the connector reads; the body is here only so no test can pass against an empty one |
| the exact wording of the 422 body (`Too recent start_time…`) | same — and the documented floor is quoted variously as one and as five minutes, so `start_time_lag_seconds` is set to 300 to stay clear of both |
| the `after_cursor` encoding (a base64 blob of roughly 30 characters) | documented as opaque, so its length is an estimate. `ZendeskConnector::MAX_TOKEN_LENGTH` refuses anything over 120 characters rather than overflowing `integrations.sync_cursor`, which is `varchar(255)` |
| the exact incremental-export request budget | documented as separate and small; `config/connectors.php` sets 10 per minute, which is the conservative end |
| `https://{subdomain}.zendesk.com/agent/tickets/{id}` as `source_url` | the agent-interface deep link, not an API-documented field. The ticket's own `url` is the `.json` API endpoint, which is useless to a human opening it from the inbox |
| `null` (rather than `{"score": "unoffered"}`) as a possible `satisfaction_rating` | both appear in the wild; the connector treats them the same, and `page-2-end.json` carries one of each |

## How the connector maps a ticket

| `ConnectorItem` | from |
|---|---|
| `externalId` | `id`, as a string |
| `author` | `via.source.from.name`, else `null`. The ticket carries only a numeric `requester_id`; resolving it would mean sideloading the user record and pulling a full profile, email address included, into `raw_payload` |
| `body` | `description` |
| `sourceUrl` | `https://{subdomain}.zendesk.com/agent/tickets/{id}` |
| `publishedAt` | **`created_at`**, not `updated_at`: an agent's later reply must not move a comment forward in the inbox or the trend charts |
| `rating` | `satisfaction_rating.score` projected onto the 1-5 scale the product already speaks — `good` → 5, `bad` → 1, `offered`/`unoffered`/absent → `null` |
| `rawPayload` | the whole ticket |

Tickets with `status: "deleted"` are skipped: they carry no content, and
ingesting one would put an empty row in the inbox and spend a unit of analysis
quota on it.

## Why the cursor and not a watermark

The export is ordered by when a ticket last changed. A ticket created two years
ago and updated this morning arrives at the end of the stream with an old
`created_at`, so a high-water mark on `created_at` would classify it as already
seen and drop it. `ZendeskConnector` therefore carries Zendesk's own
`after_cursor` in `SyncCursor::$token` and stops on `end_of_stream` — the
watermark fields are left out of its cursor entirely, which also keeps the
encoded value comfortably inside `varchar(255)`.

## The files

| file | what it exercises |
|---|---|
| `page-1.json` | three tickets, `end_of_stream: false` — the middle of a run. Ticket 1002's description carries an email address, so the runner's PII masking is on the path |
| `page-2-end.json` | two tickets, `end_of_stream: true`. One has no CSAT at all, one is `status: "deleted"` |
| `page-empty-continues.json` | zero tickets with `end_of_stream: false` — an empty page must never end a run |
| `page-caught-up.json` | zero tickets with `end_of_stream: true` — the steady state of an incremental sync, and the proof that a second run is not a re-scan |
| `error-unauthorized.json` | the 401 body |
| `error-forbidden.json` | the 403 body |
| `error-rate-limited.json` | the 429 body |
| `error-invalid-start-time.json` | the 422 body |

## Two copies

`backend/tests/Fixtures/platforms/zendesk/` holds a byte-identical copy, for the
mount reason described in `backend/config/connectors.php`.
`backend/tests/Feature/Ingestion/PlatformFixtureParityTest.php` asserts the two
directories agree.
