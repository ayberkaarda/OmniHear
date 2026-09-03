# W8 — Google Play and Trustpilot connectors

Status: **binding for W8.** Written by the main thread before dispatch, so two
connector tracks can run in parallel without either guessing at a shared type or
touching a shared file.

Source of truth for behaviour: `docs/OMNIHEAR-SPEC.md` §2 (six channels), §6.1
(incremental fetch, full scans forbidden), §8 (credential handling).
Companion: `docs/contracts/backend-core.md` §1 (the `integrations` row),
`.claude/skills/platform-connector`.

Closes two of the nine entries in `docs/adr/0010-deliberate-scope-exclusions.md`.

---

## 0. The caveat, stated once and up front

**Neither connector will have been run against a live account.** There is no
Google Play developer account and no Trustpilot business account behind this
work. Every request shape, response envelope, field name and error status below
comes from published API documentation, and the fixtures are synthesised from
it — exactly the position `ZendeskConnector` is already in.

This is a real limitation, not a formality. It means the connectors are correct
against the documentation and unproven against the wire. Each track therefore
owes a `README.md` beside its fixtures that separates, field by field, **what is
documented** from **what is inferred** — the same file `contracts/fixtures/platforms/zendesk/README.md`
already provides. A reviewer must be able to tell the two apart without opening
the vendor's docs.

`docs/adr/0010` is updated at the end of the wave to say two of the four missing
connectors were built and under what evidence.

---

## 1. Ownership — disjoint, and enforced by directory

### Track A — `googleplay` (agent, `model: "opus"`)

Root: `C:\dev\SaaaS\backend`. Writes **only** these paths:

```
backend/app/Support/Connectors/GooglePlayConnector.php
backend/app/Support/Connectors/GooglePlayAccessToken.php      (see section 3)
backend/tests/Unit/Connectors/GooglePlayConnectorTest.php
backend/tests/Feature/Ingestion/GooglePlayIngestionTest.php
backend/tests/Fixtures/platforms/googleplay/**
contracts/fixtures/platforms/googleplay/**
```

### Track B — `trustpilot` (agent, `model: "opus"`)

Root: `C:\dev\SaaaS\backend`. Writes **only** these paths:

```
backend/app/Support/Connectors/TrustpilotConnector.php
backend/tests/Unit/Connectors/TrustpilotConnectorTest.php
backend/tests/Feature/Ingestion/TrustpilotIngestionTest.php
backend/tests/Fixtures/platforms/trustpilot/**
contracts/fixtures/platforms/trustpilot/**
```

### Main thread — nobody else touches these

```
backend/config/connectors.php                       platform entries
backend/app/Support/Connectors/ConnectorFactory.php  two match arms
backend/app/Http/Requests/**                        if platform validation needs it
frontend/src/app/shared/labels/domain-labels.ts      platform labels
frontend/src/locale/messages.xlf, messages.tr.xlf    the label strings
docs/PROGRESS.md, docs/adr/0010-*.md                 the record
```

Neither agent edits `ConnectorFactory` or `config/connectors.php`. They are the
only files both tracks would need, which is exactly why the main thread owns
them. **Consequence both agents must design around: the factory cannot build
your connector during your work.** Your tests construct the connector directly
with `new`, the way `AppStoreConnector`'s unit tests already do. The
factory-level test is the main thread's, written after both classes land.

### Test databases (§5)

Track A: `DB_DATABASE=test_tmp_w8gp php artisan test --filter=GooglePlay`
Track B: `DB_DATABASE=test_tmp_w8tp php artisan test --filter=Trustpilot`

Each agent drops its own database when it is finished, by explicit name, one at
a time. Wildcards are forbidden (§8). `omnihear` and `omnihear_test` are never
touched.

---

## 2. What does not change

`PlatformConnector`, `ConnectorPage`, `ConnectorItem`, `ConnectorLimits`,
`ConnectorHealth`, `ConnectorFailure`, `ConnectorException`, `SyncCursor` and
`IngestionRunner` are **fixed**. Neither track modifies any of them.

If a connector cannot be expressed within these types, that is a contract
finding: stop, report it, and do not widen the interface unilaterally. Both
platforms below were checked against these types before this document was
written and both fit.

Re-read the semantics on `ConnectorPage` before writing a line — they are load
bearing and one of them has already cost this project a debugging session:

1. `items === []` says nothing about the stream. Only `hasMore` does.
2. `hasMore === true` requires a non-null `nextCursor` (the constructor asserts).
3. The runner owns the page cap. A connector that hits its own ceiling must
   **not** report `hasMore === false` — that tells the runner the run completed
   and lets it promote a position it never reached.
4. The watermark on a page is the highest `publishedAt` **on that page**. The
   runner decides when it becomes the stored position; the connector never
   promotes it mid-run. (`docs/LESSONS.md`, cursor watermark entry.)

---

## 3. Track A — Google Play

### The endpoint

```
GET https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{packageName}/reviews
    ?maxResults={n}&token={pageToken}
Authorization: Bearer {access_token}
```

Response envelope: `{ "reviews": [...], "tokenPagination": { "nextPageToken": "..." } }`.
`tokenPagination` is **absent** on the last page — that absence is the end of the
stream, and it is the only thing that ends it.

Review shape (the fields this connector reads):

```
reviewId                                        -> externalId
authorName                                      -> author (nullable)
comments[].userComment.text                     -> body
comments[].userComment.lastModified.seconds     -> publishedAt (epoch seconds)
comments[].userComment.starRating               -> rating (1..5)
```

A review's `comments` array holds a `userComment` and optionally a
`developerComment`. Only `userComment` is feedback; a review whose `comments`
carries no `userComment` with non-empty `text` is **skipped**, not stored as an
empty body.

### The seven-day window — this decides the cursor model

`reviews.list` returns only reviews from roughly the **last week**. There is no
way to ask for older ones, and a page token does not survive between runs.

So Google Play is the **App Store pattern, not the Zendesk pattern**: newest
first, re-listed every run, with a stored watermark deciding when to stop.

- `SyncCursor::$token` carries the `nextPageToken` **within one run only**.
- `SyncCursor::$watermark` is the position across runs.
- Paging stops when the page's items are all at or below the stored watermark,
  when `tokenPagination` is absent, or when the runner's page cap trips.
- Rows already seen are caught by `UNIQUE (integration_id, external_id)` —
  invariant **I2**. Re-listing inside the window is expected and cheap; it is
  not a full scan, because the window is what the platform offers.
- The `pending` field on `SyncCursor` exists for exactly this shape. Read
  `AppStoreConnector` and the runner's promotion rule before using it.

This satisfies spec §6.1: the run stops at the stored position rather than
walking the feed to its end.

### Authentication — the part that is new

A service account, not an API key. Two steps, and the second is the reason this
track is `opus`:

1. Build and RS256-sign a JWT asserting `iss = client_email`,
   `scope = https://www.googleapis.com/auth/androidpublisher`,
   `aud = https://oauth2.googleapis.com/token`, `iat`, `exp` (max one hour).
2. Exchange it at `POST https://oauth2.googleapis.com/token` with
   `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer` for an
   `access_token` and its `expires_in`.

Put this in **`GooglePlayAccessToken`**, separate from the connector. The
connector's job is pages; token minting is its own concern with its own tests,
and mixing them makes both harder to reason about.

Cache the access token in Laravel's cache, keyed so that **two tenants never
share one**, for `expires_in` minus a safety margin. A token is a credential:
the cache key is derived from the integration id, never from any part of the
credential itself.

`openssl_sign` with `OPENSSL_ALGO_SHA256` is available in the backend image —
no new composer dependency. If you conclude one is unavoidable, that is a
contract finding: stop and report, do not add it.

### Invariant I5 — non-negotiable, and harder here than anywhere so far

The service-account private key is the most dangerous credential in this
codebase. All three of `ZendeskConnector`'s structural rules apply, plus one:

1. Credentials are constructor arguments in private properties, handed to the
   signer and to `withToken()`. Never in a URL, never in a query string, never
   read back out.
2. **Nothing thrown is built from a response.** Every failure is one of the
   fixed `ConnectorFailure` sentences.
3. The class logs nothing at all.
4. **The private key is never serialized, never in an array cast, and never in
   an exception's context.** `openssl_sign` failing must produce
   `ConnectorFailure::Misconfigured` and nothing derived from `openssl_error_string()`.

`sensitive-log-guard` will flag violations. Do not silence it; fix the code.

### Settings and credentials

```
settings:     package_name   (e.g. "com.acme.app")
credentials:  client_email, private_key
```

`package_name` goes into the URL path. It is whitelisted, not escaped — the same
reasoning as `ConnectorFactory::subdomain()`. Java package syntax only:
`/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/`. Anything else is
`Misconfigured`. **The whitelist regex lives in your connector's constructor**,
because the factory is not yours to edit; the main thread will not duplicate it.

### Constructor — fixed, the factory is written against this

```php
public function __construct(
    private string $packageName,
    private GooglePlayAccessToken $token,
    private string $baseUrl,          // https://androidpublisher.googleapis.com
    private ConnectorLimits $limits,
    private int $timeout,
    private int $maxResults,          // per page, <= 100
) {}
```

```php
final class GooglePlayAccessToken
{
    public function __construct(
        private string $clientEmail,
        private string $privateKey,
        private int $integrationId,   // cache key derivation only
        private string $tokenUrl,     // https://oauth2.googleapis.com/token
        private int $timeout,
    ) {}

    /** @throws ConnectorException */
    public function get(): string;
}
```

Do not change these signatures. If one is wrong, say so before writing code.

### Error mapping

> **Corrected 2026-09-03, mid-wave.** The first version of this table named
> three `ConnectorFailure` cases that do not exist — `AuthFailed`,
> `TemporarilyUnavailable`, `UnexpectedResponse`. The enum has exactly six:
> `Unreachable`, `InvalidCredentials`, `RateLimited`, `DepthLimitExceeded`,
> `MalformedResponse`, `Misconfigured`. Both tracks caught it and neither
> invented a case; the table below is the real vocabulary.

| HTTP | `ConnectorFailure` |
|---|---|
| 401, 403 | `InvalidCredentials` |
| 404 | `Misconfigured` (unknown package, or the account cannot see it) |
| 429 | `RateLimited` |
| **400** | `Misconfigured` — **decided mid-wave**, see below |
| 5xx, connection error, timeout | `Unreachable` |
| unparseable body, `reviews` present but not a list | `MalformedResponse` |

**400 is terminal, not transient.** `ConnectorFailure::isTransient()` is true only
for `Unreachable` and `RateLimited`, and `FetchFeedbackJob` retries on that — so
mapping 400 to `Unreachable` spends five identical attempts before the user sees
anything, and blames the platform for a problem that is in the integration
settings. A 400 on a request carrying no page token means the request shape or the
package name is not acceptable, and that repeats identically however often it is
retried.

A 400 on a request that *did* carry a page token is a different thing and is
retried once without it: a run cut short by the runner's page cap leaves a token
in the cursor, and a token that has gone stale between runs would otherwise wedge
the integration permanently. That recovery was not anticipated by this contract
and is the connector's own.

**An absent `reviews` key is an empty page, not a malformed response** — also
decided mid-wave. The protobuf-to-JSON mapping omits empty repeated fields, so an
application with nothing in the seven-day window answers `{}`, and refusing that
would report a healthy integration as permanently broken. `{}` and `[]` are
indistinguishable once decoded, so the pair is accepted together and a *non-empty*
top-level list is what gets refused as "not an envelope".

Match `ZendeskConnector`'s existing mapping where the two overlap; read it first
rather than inventing a second vocabulary.

---

## 4. Track B — Trustpilot

### The endpoint

```
GET https://api.trustpilot.com/v1/business-units/{businessUnitId}/reviews
    ?page={n}&perPage={m}&orderBy=createdat.desc
apikey: {api_key}
```

The key travels in an `apikey` **header**, not a query parameter. Putting it in
the query string would write it into every access log between here and
Trustpilot — that is an I5 violation even though the value never reaches ours.

Response: `{ "reviews": [ ... ] }`, newest first.

Review shape (the fields this connector reads):

```
id                     -> externalId
consumer.displayName   -> author (nullable)
title + text           -> body   (see below)
stars                  -> rating (1..5)
createdAt              -> publishedAt (ISO-8601)
```

`title` and `text` are separate fields and both carry meaning. Concatenate as
`title` + `"\n\n"` + `text` when both are present; use whichever exists when only
one does; skip the review when neither does. Whatever you choose, the choice is
a documented decision in the class docblock and a test asserts it — the analyzer
sees this string and nothing else.

### Cursor model — page number plus watermark

Newest-first and page-based, which is `AppStoreConnector`'s shape almost exactly.
Read that class first; this one should look like its sibling, not like a second
dialect.

- `SyncCursor::$page` carries the page number within a run.
- `SyncCursor::$watermark` is the highest `createdAt` seen across runs.
- Paging stops at the stored watermark, on a short page
  (`count(reviews) < perPage`), or at the runner's cap.
- Duplicates inside the window are caught by **I2**.

`orderBy=createdat.desc` is not optional. Without it the ordering is not
guaranteed and the watermark model is unsound; assert it in a test that inspects
the outgoing request.

### Settings and credentials

```
settings:     business_unit_id
credentials:  api_key
```

`business_unit_id` goes into the URL path: whitelist it, do not escape it.
Trustpilot's ids are 24-character hex; accept `/^[a-f0-9]{24}$/i` and refuse the
rest as `Misconfigured`. The regex lives in your connector's constructor, for
the same reason as Track A's.

### Constructor — fixed

```php
public function __construct(
    private string $businessUnitId,
    private string $apiKey,
    private string $baseUrl,          // https://api.trustpilot.com
    private ConnectorLimits $limits,
    private int $timeout,
    private int $perPage,             // <= 100
) {}
```

### Error mapping

Same table as Track A — 401/403 → `InvalidCredentials` covers both a bad key and a
business unit the key cannot read; 404 → `Misconfigured`; 429 → `RateLimited`;
everything else → `Unreachable`. Trustpilot has no equivalent of Google Play's
stale-token case, so it has no 400 arm.

---

## 5. Both tracks — what "done" means

A track is done when all of these are true, each with a command and its real
output in the report (§2 — a claim without output is not a claim):

- [ ] `healthCheck()` implemented, returning `ConnectorHealth`, and tested for
      both the healthy and the unauthenticated case.
- [ ] `limits()` returns the injected `ConnectorLimits`.
- [ ] Fixtures under `backend/tests/Fixtures/platforms/<platform>/`, mirrored to
      `contracts/fixtures/platforms/<platform>/`, with the provenance README
      described in section 0. **The fixtures contain no real person's data** —
      no real reviewer names, no real profile URLs, no real business ids. This
      repository is public and has already paid for that mistake once
      (`docs/LESSONS.md`, history rewrite).
- [ ] A unit test per: first run with no cursor · a second page · the last page ·
      an empty page · a malformed body · each error status in the table.
- [ ] A feedback-ingestion test that runs `IngestionRunner` against the fixtures
      through `Http::fake()` and asserts rows land with the right
      `company_id`, `external_id` and `published_at`. **Not `rating`** — corrected
      mid-wave: `feedbacks` has no such column, so `ConnectorItem::$rating` only
      reaches the database inside `raw_payload`. Assert the mapping directly in
      the unit test and through `raw_payload` at the database layer.

> **`Http::fake()` merges stub callbacks; it does not replace them.** A second
> `Http::fake(closure)` in a test that already installed one leaves the first in
> charge, so the later phase of a two-phase test never runs while the test stays
> green. It cost both tracks a debugging pass. Use one closure driven by a
> mutable script and assert the request count.
- [ ] An I2 test: the same page ingested twice produces no second row.
- [ ] An I5 test: force a failure and assert `integrations.sync_error` contains
      no substring of any credential.
- [ ] `published_at` keeps its offset. Use `toIso8601String()`, never
      `toDateTimeString()` — the latter drops the offset and the column is
      `timestamptz`. This has already been fixed once (`docs/LESSONS.md`).
- [ ] `vendor/bin/pint --test` clean, and the track's own tests green on its own
      `test_tmp_*` database.

## 6. Rules that travel with the dispatch

- **§1 Git.** `git status`, `diff`, `log`, `ls-files`, `show`, `blame` only. No
  commit, branch, stash, checkout, push, no `gh` write. Leave changes in the
  working tree and say what you changed.
- **§2 Evidence.** "Works", "tested", "verified" require the command and its real
  output. A test that was not run is not reported. Fixtures cover the shapes they
  cover — an inline JSON literal is not evidence for a shape a fixture owns.
- **§6 Scope and language.** Nothing outside this document gets built. Ideas go
  in "Öneriler (uygulanmadı)". Code, identifiers and comments in English; the
  report to the user in Turkish.
- **§8 Destructive commands.** No `migrate:fresh`, `db:wipe`, `FLUSHALL`,
  `docker compose down -v`, no wildcard database drop. Your own
  `test_tmp_<suffix>` may be dropped by explicit name after checking
  `pg_stat_activity`, one at a time.
