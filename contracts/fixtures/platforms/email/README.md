# Shared-mailbox (JMAP) fixtures — provenance

**Nothing here was captured. Every byte is synthetic.**

There is no JMAP account behind this project. These files were written from
RFC 8620 (JMAP core) and RFC 8621 (JMAP mail) so that `EmailConnector` and the
whole ingestion path can be exercised with `Http::fake()`. They contain **no
real sender, no real message, no real mailbox, no real account and no real
credential** — the senders are `sender-01` … `sender-08` at `example.invalid`
(a domain RFC 2606 reserves so it can never resolve), every id is a made-up
`em-000N` / `mbx-name` / `blob-000N` string, and every server state is
`emstate-000N`.

That is a policy, not an accident. It is the same rule the App Store fixtures
were rewritten to satisfy (decision D-06, `docs/PROGRESS.md`): **no real
person's data enters a fixture.** This repository is public and paid for that
mistake once with a full history rewrite (`docs/LESSONS.md`).

`backend/tests/Unit/Connectors/EmailConnectorTest.php` asserts the promise
rather than only stating it: a test walks every fixture and refuses any sender
address outside `example.invalid`, any display name that is not `sender-NN`,
and any host in a URL that is not `example.invalid`.

This channel is a **protocol, not a vendor** — the credential is a session URL,
so any conforming JMAP server answers it. That makes the RFCs the primary
source here, where Trustpilot's fixtures had only vendor documentation. It also
means the second table below has a different character: what is uncertain is
rarely *what the protocol says* and almost always *what a particular server
does with it*.

## Verified against the RFCs vs inferred

None of this was measured against a live server, so the distinction matters
more here than for a captured fixture. **Anything in the second table is a
place to look first if a real account behaves differently.**

### Taken from the RFCs

| what | where |
|---|---|
| session resource: `capabilities`, `accounts`, `primaryAccounts`, `username`, `apiUrl`, `downloadUrl`, `uploadUrl`, `eventSourceUrl`, `state` | RFC 8620 §2 |
| the core capability object: `maxSizeUpload`, `maxConcurrentUpload`, `maxSizeRequest`, `maxConcurrentRequests`, `maxCallsInRequest`, `maxObjectsInGet`, `maxObjectsInSet`, `collationAlgorithms` | RFC 8620 §2 |
| capability URIs `urn:ietf:params:jmap:core` and `urn:ietf:params:jmap:mail` | RFC 8620 §2, RFC 8621 §1 |
| request envelope: `{ "using": [...], "methodCalls": [[name, arguments, callId], …] }` | RFC 8620 §3.2 |
| response envelope: `{ "methodResponses": [[name, arguments, callId], …], "sessionState": … }` | RFC 8620 §3.3 |
| result references — `"#ids": { "resultOf": "q0", "name": "Email/query", "path": "/ids" }` — which is what makes one page **one HTTP request** | RFC 8620 §3.7 |
| method-level errors are a `["error", { "type": … }, callId]` triple | RFC 8620 §3.6.2 |
| the method-error vocabulary this connector maps: `serverFail`, `serverPartialFail`, `serverUnavailable`, `forbidden`, `accountNotFound`, `invalidResultReference` | RFC 8620 §3.6.2 |
| request-level errors are an RFC 7807 problem-details document with a `urn:ietf:params:jmap:error:*` type — `notJSON`, `notRequest`, `unknownCapability`, `limit` | RFC 8620 §3.6.1 |
| `/get` arguments and response: `accountId`, `ids` (`null` = every object), `properties`; `state`, `list`, `notFound` | RFC 8620 §5.1 |
| `/changes` arguments and response: `sinceState`, `maxChanges`; `oldState`, `newState`, `hasMoreChanges`, `created`, `updated`, `destroyed` | RFC 8620 §5.2 |
| `cannotCalculateChanges` — the server can no longer compute changes from that state, and the client must resynchronise | RFC 8620 §5.2 |
| `/query` arguments and response: `filter`, `sort`, `position`, `limit`, `calculateTotal`; `queryState`, `canCalculateChanges`, `position`, `ids` | RFC 8620 §5.5 |
| `UTCDate` is `YYYY-MM-DDTHH:MM:SSZ` — an instant in UTC with a literal `Z` | RFC 8620 §1.4 |
| Mailbox object: `id`, `name`, `parentId`, `role`, and the role vocabulary (`inbox`, `archive`, `sent`, …) | RFC 8621 §2 |
| **`Mailbox/query`'s `name` condition is a substring match** — which is why this connector does not use it | RFC 8621 §2.3 |
| Email metadata: `id`, `blobId`, `threadId`, `mailboxIds` (an `Id[Boolean]` map whose values are always `true`), `size`, `receivedAt` | RFC 8621 §4.1.1 |
| Email header conveniences: `messageId`, `from` (a list of `{ name, email }`), `subject`, `sentAt` | RFC 8621 §4.1.2 |
| Email body parts: `textBody`, `htmlBody`, `bodyValues` keyed by `partId`, `preview`, and the part fields `partId`, `blobId`, `size`, `type`, `charset` | RFC 8621 §4.1.4 |
| `textBody` carries the **`text/html`** part when the message has no plain alternative — so "the text body" is not automatically text | RFC 8621 §4.1.4 |
| `bodyValues` entries carry `value`, `isEncodingProblem`, `isTruncated` | RFC 8621 §4.1.4 |
| `Email/get`'s `fetchTextBodyValues` and `maxBodyValueBytes` | RFC 8621 §4.2 |
| **`Email/changes` takes no mailbox filter — it is account-wide** | RFC 8621 §4.3 |
| `Email/query` FilterCondition: `inMailbox`, `after`, `before` — `after` matches on `receivedAt` | RFC 8621 §4.4.1 |
| sorting on `receivedAt` | RFC 8621 §4.4.2 |

### Inferred, and worth re-checking against a live account

| what | why it is a guess |
|---|---|
| **the HTTP status codes** in `error-unauthorized.json` (401), `error-forbidden.json` (403), `error-not-found.json` (404), `error-rate-limited.json` (429) and `error-not-request.json` (400) | RFC 8620 describes the authentication mechanism (§2, §8) and the request-level error document (§3.6.1) without pinning one status code per condition. The connector reads **only the status code**, so this mapping is the load-bearing guess on the failure path. The bodies exist so no test can pass against an empty one |
| **the shape of the bodies** in the four `about:blank` error files | `error-not-request.json` follows the documented `urn:ietf:params:jmap:error:notRequest` type; the other three are plausible RFC 7807 documents, not documented ones. Nothing reads them |
| that a server actually **honours the `Email/query` → `Email/get` result reference in one request** | §3.7 documents the mechanism and `maxCallsInRequest` (16 here) permits it, but whether a given server evaluates `/ids` from a `Email/query` result is a server property. If one does not, the connector's whole "one request per page" claim collapses into an N+1 and the design has to be revisited |
| the **length and alphabet of a server `state`** — `emstate-0001` here, and `MAX_TOKEN_LENGTH = 200` in the connector | states are opaque (§5.1) and no length is specified. 200 characters is a guess sized to `integrations.sync_cursor`, which is `varchar(255)`. A server with longer states would fail every run with `MalformedResponse` |
| `"maxObjectsInGet": 500` and the other core-capability numbers | the *fields* are documented, the *values* are per-server. The connector clamps its page size to whatever the session says, so a smaller real value is handled — but only the clamp is tested, not the number |
| that `preview` is **present and non-empty** for a message with an HTML-only body | `preview` is documented (§4.1.4) as a plain-text extract, but neither its presence on every message nor its length is guaranteed by the RFC. `em-0002` is the whole basis of the HTML-only branch: if a real server omits `preview`, an HTML-only message falls through to subject-only |
| that a mailbox has a **stable `name`** the user can paste, and that `Inbox` / `Support` / `Archive` / `Sent` is a realistic list | the object is documented; a real account's folder set, its localisation (a Turkish account may answer `Gelen Kutusu`) and its capitalisation are not. The connector's case-insensitive fallback exists precisely because of this |
| **when** a server answers `cannotCalculateChanges` — state expiry, too many changes, a server restart | the error is documented; the policy that triggers it is not. The recovery branch is exercised by `changes-cannot-calculate.json`, but how often a real account hits it is unknown, and that frequency decides whether the bounded recovery below is acceptable |
| that `Email/changes` returns `["error", {"type": "invalidResultReference"}, "g0"]` for the chained `Email/get` when the first call errors | consistent with §3.6.2 and §3.7 — a reference to a result that does not exist — but the exact behaviour when the referenced call itself failed is not spelled out. The connector checks `c0` before it looks at `g0`, so it does not depend on this |
| `receivedAt` values ending in `Z` with whole-second precision | `UTCDate` is documented (§1.4); whether a server answers sub-second precision or a different-but-valid rendering is not fixed. `published_at` is compared as an **instant**, never as a string, so a different rendering is survivable |
| the `"(konu yok)"` subject on `em-0006` as a stand-in for a message with no words in its body | a real server returns whatever the sender wrote — including an absent `subject` key. The message is here to exercise the **empty text with a non-empty subject** branch; the "both empty, skip" branch is exercised by blanking that subject from the fixture at run time in the unit test, not by a fixture of its own |
| the per-account request budget | not published by the RFC; it is a server policy. `config/connectors.php` should be set at the conservative end (the main thread's file, not this track's) |

### Not covered here at all

- **No fixture reproduces a message moved into the watched mailbox long after
  it arrived.** Its `receivedAt` is old, so the cold-start `after` filter and
  the `cannotCalculateChanges` recovery would both skip it, while the normal
  `Email/changes` path would pick it up. That asymmetry is a real consequence
  of the bounded recovery and is recorded as an open question rather than
  fixed here — see "The cursor model" below.
- **No fixture carries an attachment's content**, only `hasAttachment`. The
  connector never downloads a blob.
- **No fixture exercises `isTruncated: true`.** `maxBodyValueBytes` is sent on
  every request, so a long message will come back truncated in production; the
  connector stores the truncated text and does not flag it.
- **No fixture carries `to`, `cc` or `bcc`.** They are deliberately absent from
  the requested `properties` — they are the company's own staff addresses and
  carry no analytical signal, which is spec §8 data minimisation applied one
  step earlier than `IngestionRunner`'s masking.
- **No fixture carries a real address.** The runner masks addresses out of
  `body`, `author` and `raw_payload` on every platform
  (`IngestionRunner::maskPii`); that path is already covered by the Zendesk
  fixtures.
- **Push (RFC 8620 §7) is not used at all.** This connector polls.

## What a live recording would settle

These fixtures are due to be re-recorded against a **Fastmail trial account**:
envelope-real, content-synthetic, the way the App Store fixtures were rebuilt.
This README is written so that transition is a **narrowing of the inferred
table, not a rewrite** — the documented table cites RFCs and does not move, and
each inferred row below names the fixture that would carry the answer.

A live recording settles, in the order it matters:

1. **The failure statuses.** Which of 401 / 403 / 404 / 429 / 400 a real server
   actually answers, and what its error bodies look like. → the five `error-*`
   files. This is the largest guess in the set and the only one the connector's
   behaviour depends on directly.
2. **Whether the result reference works in one request.** → `page-1.json`
   staying a single request/response pair. If it does not, the connector's
   central design claim fails and W11 reopens.
3. **The real `state` format and length.** → every `state` / `newState` /
   `sinceState` value, and with them `MAX_TOKEN_LENGTH`.
4. **The real core-capability values**, `maxObjectsInGet` above all. →
   `session.json`.
5. **Whether `preview` is really there for an HTML-only message.** →
   `page-1.json`'s `em-0002`. If it is absent, the HTML branch needs a
   different answer than "use the preview".
6. **A real mailbox list**: names, roles, localisation, capitalisation. →
   `mailboxes.json`, and with it whether the case-insensitive fallback is
   enough.
7. **What `cannotCalculateChanges` costs in practice** — whether a real account
   hits it at all. → `changes-cannot-calculate.json`, and the open question
   about the bounded recovery.
8. **`receivedAt` precision and rendering.** → every message.

What a live recording **cannot** settle, and what must therefore stay
synthetic: the message bodies, the subjects, the sender names and every
address. Those are the D-06 rule and it does not bend for a real account —
the recording supplies the **envelope**, this file's content stays invented.

## How the connector maps a message

| `ConnectorItem` | from |
|---|---|
| `externalId` | the `Email` object's `id` — server-assigned, stable, unique per account |
| `author` | `from[0].name` when present, else the **local part** of `from[0].email`. Never the whole address: `IngestionRunner::maskPii` rewrites anything matching an address to `[email]`, so passing the address through would put the literal string `[email]` in every author column and throw the sender's identity away |
| `body` | **`subject` + `"\n\n"` + text** when both are present; whichever exists when only one is; the message is **skipped** when neither is. "Text" means the `text/plain` parts' `bodyValues`, or `preview` when the message is HTML-only |
| `sourceUrl` | `null`. JMAP has no canonical web URL for a message — `downloadUrl` serves the raw blob and is not a page a person can open, so nothing is invented |
| `publishedAt` | `receivedAt`, kept **with its offset**: `IngestionRunner` writes `toIso8601String()`, because `toDateTimeString()` drops the zone and has already put a row seven hours off the real instant once (`docs/LESSONS.md`) |
| `rating` | `null`. E-mail carries no rating, and `ConnectorItem::$rating` is nullable for exactly this case. There is **no `feedbacks.rating` column** either way — the value reaches the database only inside `raw_payload` |
| `rawPayload` | the whole `Email` object as it arrived, masked by the runner |

### Why `subject` and the text are joined

Both carry meaning and the analyzer sees `feedbacks.body` and nothing else. On
e-mail the subject is frequently where the whole sentiment lives — "Still no
refund" is the complaint, and the line under it may be one sentence of detail.
Dropping either throws away signal the sentiment and category analysis is built
on. This is the same decision `TrustpilotConnector` documents for `title` plus
`text`, taken for the same reason, and it is why a message is skipped **only**
when both halves are empty: a blank row would sit in the inbox and spend a unit
of analysis quota on nothing.

### Why the watched mailbox is enforced here and not in the query

`Email/changes` is **account-wide** (RFC 8621 §4.3) — it takes no mailbox
filter. So every fetched message is checked against
`mailboxIds[<watched id>] === true` before it becomes an item. Without that
check a company watching `Support` would ingest its own `Sent` folder as
customer feedback. `changes-1.json`'s `em-0005` lives in `Archive` and exists
solely to prove the exclusion.

### Why the mailbox is resolved by name, and why that costs two extra requests

The credential names the mailbox (`"Support"`) rather than carrying its id,
deliberately: ids are per-account opaque strings and a value a user pastes into
a form must not encode one. Resolving it means a session `GET` plus a
`Mailbox/get`, both memoised for the run — so "one request per page" is
honestly **one request per page plus two per run**.

`Mailbox/query`'s `name` condition is a **substring** match (RFC 8621 §2.3), so
asking for `Support` could silently select `Support Archive`. The connector
therefore fetches the whole list with `Mailbox/get { ids: null }` and matches
the name itself: exact first, case-insensitive as a fallback — a user who types
`inbox` means `Inbox` — and `Misconfigured` when nothing matches, because a
mistyped mailbox name is the most likely configuration error on this channel
and it must not present as "no feedback yet".

## The cursor model

Zendesk's shape, not the App Store's: `SyncCursor::$token` only, carrying the
server's own opaque state. **No `published_at` watermark is used at all** —
`Email/changes` is ordered by change, not by `receivedAt`, so a high-water mark
on `receivedAt` would classify an old message *just filed into the mailbox* as
already seen and drop it.

- **No token (first run):** `Email/query` filtered to the mailbox and to
  `after = now − initial_lookback_days`, sorted newest-first, chained into
  `Email/get`. The `Email/get` `state` becomes the token. One page,
  `hasMore: false`.
- **With a token:** `Email/changes { sinceState }` chained into `Email/get` on
  `/created`. `hasMoreChanges` becomes `hasMore`; `newState` becomes the next
  token.
- **`cannotCalculateChanges`:** a normal branch, not an exception — fall back to
  the full `Email/query` and take a fresh state.
- The token is written to `integrations.sync_cursor` by `IngestionRunner` at
  the **end** of a run, never mid-run. A run that dies on page 3 leaves the
  stored token where it was and the next run walks the same ground again;
  invariant **I2** — `UNIQUE (integration_id, external_id)` — turns the
  re-fetch into zero new rows.
- **The runner's `maxPagesPerRun` is not a stop condition of this connector.**
  `hasMore` is `hasMoreChanges` and nothing else. Reporting `hasMore=false` on
  reaching the runner's cap would tell the runner the run completed and let it
  store a position it never reached (`docs/LESSONS.md`).

**The cold start is bounded, and so is the recovery.** Anything received longer
ago than `initial_lookback_days` is **never read** — this is not a full
backfill, and the setting mirrors Zendesk's. The recovery from
`cannotCalculateChanges` runs the same bounded query, which leaves one gap
worth naming: a message *moved* into the watched mailbox after arriving long
ago has an old `receivedAt`, so a recovery would not see it, while an ordinary
`Email/changes` run would. Whether that matters depends on how often a real
server answers `cannotCalculateChanges` — item 7 of the live-recording list.

## The files

| file | what it exercises |
|---|---|
| `session.json` | the session resource: capabilities, `apiUrl`, `primaryAccounts`, `maxObjectsInGet` |
| `mailboxes.json` | `Mailbox/get` with four folders. `Support` is the watched one; `Archive` and `Sent` are what the account-wide `Email/changes` must not ingest |
| `page-1.json` | the cold start: `Email/query` + `Email/get` in one response, three messages. `em-0001` is plain text; `em-0002` is **HTML-only** and must fall back to `preview`; `em-0003` has a **null display name** and lives in two mailboxes at once |
| `changes-1.json` | `Email/changes` with `hasMoreChanges: true` — the run continues. `em-0004` is in `Support`; **`em-0005` is in `Archive` only and must be excluded** |
| `changes-2-last.json` | `hasMoreChanges: false` — the last page. `em-0006` has an **empty text body** and a non-empty subject, so it is ingested subject-only; `em-0007` is ordinary |
| `changes-none.json` | nothing changed: empty `created`, `newState` equal to `sinceState`, `hasMoreChanges: false` |
| `changes-cannot-calculate.json` | the stale-state branch: `["error", {"type":"cannotCalculateChanges"}, "c0"]` |
| `page-recovered.json` | the full query the connector falls back to after that error, with a fresh state |
| `error-unauthorized.json` | the 401 body |
| `error-forbidden.json` | the 403 body |
| `error-not-found.json` | the 404 body — no session resource at that URL |
| `error-rate-limited.json` | the 429 body |
| `error-not-request.json` | the 400 body, the documented `notRequest` problem type |
| `error-method-account-not-found.json` | a method-level `accountNotFound` |
| `error-method-server-fail.json` | a method-level `serverFail` — the one method error that is transient |

States chain strictly: `emstate-0001` (`page-1.json`) → `emstate-0002`
(`changes-1.json`) → `emstate-0003` (`changes-2-last.json`, then
`changes-none.json`), with `emstate-0009` as the state a recovery establishes.
The cursor tests depend on that chain.

## Two copies

`backend/tests/Fixtures/platforms/email/` holds a byte-identical copy, for the
mount reason described in `backend/config/connectors.php`: the dev compose
stack bind-mounts only `../backend`, so `contracts/` does not exist inside the
container. `backend/tests/Feature/Ingestion/PlatformFixtureParityTest.php`
asserts the two directories agree, byte for byte, and that this README exists.
