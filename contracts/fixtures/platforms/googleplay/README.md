# Google Play reviews fixtures — provenance

**Nothing here was captured. Every byte is synthetic.**

There is no Google Play developer account and no Play Console behind this
project. These files were written from the published Android Publisher API
documentation so that `GooglePlayConnector`, `GooglePlayAccessToken` and the
whole ingestion path can be exercised with `Http::fake()`. They contain no real
reviewer, no real review, no real package name and no real credential:

- every reviewer is `reviewer-01` … `reviewer-08`;
- every review id is `gp:FIXTURE-review-000N`, not a real Play review id;
- the package name used throughout the suite is `com.example.omnihear`, on the
  `example.com` domain RFC 2606 reserves so it can never belong to anyone;
- the one email address in a review body is on `example.invalid`, reserved by
  the same RFC;
- the access token in `token-response.json` is a placeholder string, and **no
  private key is committed at all** — the tests generate an RSA key pair in
  process with `openssl_pkey_new()`, so the key is different on every run and
  the signature check actually proves something.

That is a policy, not an accident. It is the same rule the App Store fixtures
were rewritten to satisfy (decision D-06, `docs/PROGRESS.md`): **no real
person's data enters a fixture.**

## Verified against documentation vs inferred

Because none of this was measured against a live account, the distinction
matters more here than for a captured fixture. Anything in the second table is a
place to look first if a real account behaves differently.

### Taken from the documentation

| what | detail |
|---|---|
| endpoint | `GET https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{packageName}/reviews` |
| paging parameters | `maxResults` (up to 100) and `token` |
| response envelope | `reviews`, `tokenPagination.nextPageToken`, `pageInfo` |
| stop condition | `tokenPagination` is **absent** on the last page |
| ordering | newest first |
| retention window | `reviews.list` only serves reviews from roughly the **last week**; there is no parameter that reaches further back |
| review fields | `reviewId`, `authorName`, `comments[]` |
| comment fields | each entry of `comments` carries a `userComment` or a `developerComment`; `userComment` has `text`, `lastModified`, `starRating`, `reviewerLanguage`, `device`, `androidOsVersion`, `appVersionCode`, `appVersionName`, `thumbsUpCount`, `thumbsDownCount`, `deviceMetadata` |
| `lastModified` | a protobuf `Timestamp`: `{"seconds": ..., "nanos": ...}` |
| protobuf JSON mapping | an int64 field (`seconds`) is encoded as a **string**, an int32 field (`starRating`, `nanos`) as a number |
| `starRating` | an integer 1-5 |
| anonymity | `authorName` is absent for a review left without a display name |
| authentication | a service account: an RS256 JWT asserting `iss`, `scope`, `aud`, `iat`, `exp`, exchanged at `POST https://oauth2.googleapis.com/token` with `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer` |
| scope | `https://www.googleapis.com/auth/androidpublisher` |
| assertion lifetime | at most one hour |
| token response | `{"access_token": ..., "expires_in": ..., "token_type": "Bearer"}` |
| token failure | `{"error": "invalid_grant", "error_description": ...}` with HTTP **400** |
| error envelope | `{"error": {"code": ..., "message": ..., "status": ..., "errors": [...]}}` |
| auth failure | **401** `UNAUTHENTICATED` |
| permission failure | **403** `PERMISSION_DENIED` |
| unknown application | **404** `NOT_FOUND` |
| quota exhaustion | **429** `RESOURCE_EXHAUSTED` |
| bad request | **400** `INVALID_ARGUMENT` |

### Inferred, and worth re-checking against a live account

| what | why it is a guess |
|---|---|
| the exact wording of every error `message` | the status code is what the connector reads; the bodies are here only so no test can pass against an empty one |
| `nextPageToken` length | documented as opaque, so the length is an estimate. `GooglePlayConnector::MAX_TOKEN_LENGTH` refuses anything over 150 characters rather than overflowing `integrations.sync_cursor`, which is `varchar(255)`. If real tokens are longer than that, this connector cannot page at all and the ceiling has to be reconsidered together with the column |
| **HTTP 400 as the answer to a page token that has expired** | Google documents neither a lifetime for the token nor a status for a stale one. `GooglePlayConnector` retries such a request once without the token, from the top of the feed, precisely because the alternative — a run that fails identically forever against a cursor it can never rewrite — is unrecoverable. If a real account answers something other than 400, the recovery never fires and the integration wedges |
| `gp:` as the `reviewId` prefix | real ids appear to carry it; nothing documents the format, and the connector treats the id as an opaque string |
| `https://play.google.com/store/apps/details?id={packageName}` as `source_url` | the public store listing, not the review. Google Play publishes no per-review permalink, and the Play Console review view is behind an account-scoped URL this product has no way to build |
| `deviceMetadata` field names | plausible rather than exhaustive; nothing in the connector reads them, they are here so `raw_payload` is realistically shaped |

## Two decided deviations from the W8 contract

Both were raised as findings rather than changed unilaterally, and both were
then decided by the contract owner. They are recorded here because the contract
document still reads the other way.

### A missing `reviews` key is an empty page, not a malformed response

The protobuf-to-JSON mapping omits empty repeated fields by default, so an
application with nothing in the seven-day window answers **`{}`**, not
`{"reviews": []}`. The W8 contract error table lists "missing `reviews` key" as
an unparseable response, which would have reported a perfectly healthy
integration as permanently broken — a worse failure than the one the check was
guarding against.

So the two shapes we accept are:

| body | read as |
|---|---|
| `{}` — no `reviews` key at all | an empty page. `hasMore` is still decided by `tokenPagination` alone |
| `{"reviews": [...]}` — a JSON list, empty or not | the page |
| `{"reviews": <anything not a list>}` | **`MalformedResponse`**, unchanged. A `reviews` that is present but is not a list genuinely is a response this connector cannot parse |
| a non-empty top-level JSON array | **`MalformedResponse`** — not an envelope. (`[]` is not in this row: it decodes to exactly what `{}` decodes to, so the pair cannot be told apart and is read as an envelope with no keys.) |

An empty window every run is the steady state of a quiet app and must never
accumulate into a failure. It does not: `IngestionRunner` keeps its empty-page
streak in a local that is reset at the top of every run, so N quiet runs are N
streaks of one, never one streak of N, and `ConnectorLimits::$maxConsecutiveEmptyPages`
is never approached. `GooglePlayIngestionTest` asserts this across four
consecutive empty runs — status stays `active`, `sync_error` stays null, the
watermark is untouched — and then asserts the next run that finds something
ingests it normally.

### HTTP 400 maps to `Misconfigured`, not to the table's `Unreachable` default

The contract error table lists no 400, which would leave it on the default
`Unreachable` row. `ConnectorFailure::isTransient()` is true for `Unreachable`,
and `FetchFeedbackJob` retries transient failures five times — so a refusal that
repeats identically would cost five attempts before the user saw anything, and
the message would blame the platform for a problem that is in the integration
settings. A 400 on a request carrying no page token means the request shape or
the package name is not acceptable, which is terminal.

The stale-token recovery is unaffected and unchanged: a 400 on a request that
**did** carry a page token is still retried once without it, from the top of the
feed, and only the second refusal is mapped.

## How the connector maps a review

| `ConnectorItem` | from |
|---|---|
| `externalId` | `reviewId` |
| `author` | `authorName`, else `null` — reviews can be left anonymously |
| `body` | `comments[].userComment.text` of the **first** entry carrying a `userComment` |
| `sourceUrl` | `https://play.google.com/store/apps/details?id={packageName}` |
| `publishedAt` | `comments[].userComment.lastModified.seconds`, converted with `toIso8601String()` so the offset survives into the `timestamptz` column |
| `rating` | `comments[].userComment.starRating` when it is 1-5, else `null` |
| `rawPayload` | the whole review, developer reply included |

A review is **skipped** when it has no `userComment` with non-empty text — a
developer reply on its own, or a star rating left without words. Ingesting one
would put an empty row in the inbox and spend a unit of analysis quota on it,
and in the developer-reply case would analyse the company answering itself.

`developerComment` is never the body. Two fixtures assert it: `page-2-end.json`
holds a review with both, and `page-skipped-comments.json` holds one with only a
developer reply. Both reply texts are prefixed `DEVELOPER-…-MUST-NOT-BE-INGESTED`
so a mapping mistake fails loudly instead of looking plausible.

## Why the watermark and not a stored page token

`reviews.list` serves roughly seven days and nothing older, and a page token is
a within-request continuation rather than a durable position. So this connector
is the **App Store** shape, not the Zendesk one: it re-lists from the top on
every run and stops as soon as it reaches the stored watermark.

- `SyncCursor::$token` carries `nextPageToken`, and only within one run. It is
  dropped as soon as the run ends.
- `SyncCursor::$watermark` is the position across runs, and stays frozen for the
  whole run; `pending` accumulates and `IngestionRunner` promotes it once, only
  when the run actually reached the end of the stream.
- Everything re-listed above the watermark is absorbed by
  `UNIQUE (integration_id, external_id)` — invariant I2.

That is not the full re-scan spec 6.1 forbids: the window is the entire feed the
platform offers, and the run still stops at the stored position rather than
walking to the end of it.

## Invariant I5 — where the credentials are and are not

The service-account private key is the most dangerous credential in this
codebase, and it lives only in `GooglePlayAccessToken`:

- `client_email` and `private_key` are private constructor properties, handed to
  `openssl_sign` and to the `iss` claim, and read back nowhere;
- the client email travels only inside the signed assertion, base64url encoded,
  never as a readable form field or a URL parameter;
- the minted access token travels only in the `Authorization` header;
- the access-token cache key is `connector:googleplay:access-token:{integration_id}`,
  derived from the integration id and from nothing in the credential, so two
  tenants configuring the same service account still cannot read each other's
  token (invariant I1);
- nothing thrown is built from a response, and `openssl_error_string()` is never
  read — that buffer holds OpenSSL rendering of the material it just failed to
  parse.

## The files

| file | what it exercises |
|---|---|
| `page-1.json` | three reviews with `tokenPagination` — the middle of a run. Review 0002 carries an email address so the runner PII masking is on this path; review 0003 has no `authorName` |
| `page-2-end.json` | two reviews and **no** `tokenPagination` — the last page. Review 0004 carries a developer reply alongside the user comment |
| `page-skipped-comments.json` | three reviews, one ingestable: one has only a `developerComment`, one has a `userComment` with empty `text` |
| `page-empty-continues.json` | zero reviews with `tokenPagination` present — an empty page must never end a run |
| `page-empty-window.json` | literally `{}` — an application with nothing in the seven-day window. Read as an empty page, not as a broken response; see the decided deviations above |
| `token-response.json` | the successful JWT-bearer exchange |
| `error-unauthorized.json` | the 401 body |
| `error-forbidden.json` | the 403 body |
| `error-not-found.json` | the 404 body |
| `error-rate-limited.json` | the 429 body |
| `error-invalid-page-token.json` | the 400 body that stands for a page token the platform no longer accepts |
| `error-invalid-grant.json` | the token endpoint 400 body |

## Two copies

`backend/tests/Fixtures/platforms/googleplay/` holds a byte-identical copy, for
the mount reason described in `backend/config/connectors.php`.
`backend/tests/Feature/Ingestion/PlatformFixtureParityTest.php` asserts the two
directories agree.
