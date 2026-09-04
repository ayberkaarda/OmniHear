# Shared-mailbox (JMAP) fixtures — provenance

**The envelope is real. The messages are not.**

These files were recorded on **2026-09-05** against a live **Fastmail** account
over JMAP — the session resource, `Mailbox/get`, `Email/query` chained into
`Email/get`, `Email/changes` chained into `Email/get`, and every error body the
server would produce without abusing it. Every response *shape* below is what
that server actually returned. Every message *inside* the shape was written for
this repository.

The account was a trial mailbox belonging to the repository owner. It contained
four messages: two Fastmail welcome mails, one message the owner sent in and
then filed into `Archive`, and the owner's own reply sitting in `Sent`. **None
of that text is here.** Sender display names, the addresses they came from,
subjects, body values and previews are all invented; ids, blob ids, thread ids, server states, the account
id, the user name and every host are synthetic strings that keep the recorded
**length and character class** and nothing else.

That is a policy, not an accident. It is the same rule the App Store fixtures
were rewritten to satisfy (decision D-06, `docs/PROGRESS.md`): **no real
person's data enters a fixture.** This repository is public and paid for that
mistake once with a full history rewrite (`docs/LESSONS.md`). The recording was
written to a scratchpad outside the working tree, redacted there, and checked by
a guard that slid a twelve-character window over every captured value and
refused to emit any file that shared one — 3,820 windows plus 20 short opaque
ids, across 33 files. **It caught real leakage three times**, and every one of
them had been written by somebody who believed the file was already redacted: a
Message-ID whose digits were carried across verbatim, a synthetic state string
that happened to contain a recorded state as a substring, and two recorded state
values quoted in an early draft of this very file. All three were fixed before
anything was written here, and the guard has a self-test that plants a captured
value in a phantom file and fails if it is not caught.

`backend/tests/Unit/Connectors/EmailConnectorTest.php` asserts the promise
rather than only stating it: a test walks **every** file in this directory — the
two that are not JSON included — and refuses any sender address outside
`example.invalid`, any display name that is not `sender-NN`, any Message-ID
outside `example.invalid`, any host in a URL that is not under
`example.invalid`, and a session whose `username` or account `name` names
anybody.

This channel is a **protocol, not a vendor** — the credential is a session URL,
so any conforming JMAP server answers it. One server has now been measured. The
tables below keep the RFC citations, because those still hold for every server;
what changed is that the second table shrank and a third one appeared for the
things the recording proved **wrong**.

## Taken from the RFCs

Unchanged by the recording. These are properties of the protocol, not of the
server that was measured.

| what | where |
|---|---|
| session resource: `capabilities`, `accounts`, `primaryAccounts`, `username`, `apiUrl`, `downloadUrl`, `uploadUrl`, `eventSourceUrl`, `state` | RFC 8620 §2 |
| the core capability object: `maxSizeUpload`, `maxConcurrentUpload`, `maxSizeRequest`, `maxConcurrentRequests`, `maxCallsInRequest`, `maxObjectsInGet`, `maxObjectsInSet`, `collationAlgorithms` | RFC 8620 §2 |
| capability URIs `urn:ietf:params:jmap:core` and `urn:ietf:params:jmap:mail` | RFC 8620 §2, RFC 8621 §1 |
| request envelope: `{ "using": [...], "methodCalls": [[name, arguments, callId], …] }` | RFC 8620 §3.2 |
| response envelope: `{ "methodResponses": [[name, arguments, callId], …], "sessionState": … }` | RFC 8620 §3.3 |
| result references — `"#ids": { "resultOf": "q0", "name": "Email/query", "path": "/ids" }` | RFC 8620 §3.7 |
| method-level errors are a `["error", { "type": … }, callId]` triple | RFC 8620 §3.6.2 |
| the method-error vocabulary this connector maps: `serverFail`, `serverPartialFail`, `serverUnavailable`, `forbidden`, `accountNotFound`, `invalidResultReference` | RFC 8620 §3.6.2 |
| request-level errors are an RFC 7807 problem-details document with a `urn:ietf:params:jmap:error:*` type — `notJSON`, `notRequest`, `unknownCapability`, `limit` | RFC 8620 §3.6.1 — **and see "What the recording falsified": this server only half does it** |
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
| `textBody` carries the **`text/html`** part when the message has no plain alternative | RFC 8621 §4.1.4 |
| `bodyValues` entries carry `value`, `isEncodingProblem`, `isTruncated` | RFC 8621 §4.1.4 |
| `Email/get`'s `fetchTextBodyValues` and `maxBodyValueBytes` | RFC 8621 §4.2 |
| **`Email/changes` takes no mailbox filter — it is account-wide** | RFC 8621 §4.3 |
| `Email/query` FilterCondition: `inMailbox`, `after`, `before` — `after` matches on `receivedAt` | RFC 8621 §4.4.1 |
| sorting on `receivedAt` | RFC 8621 §4.4.2 |

## Recorded from the live account

Everything here was measured. Where a number replaced a guess, the guess is in
the last column so the size of the error stays visible.

### The session

| what | recorded | the fixture used to say |
|---|---|---|
| `maxObjectsInGet` | **4096** | 500 |
| `maxCallsInRequest` | **50** | 16 |
| `maxObjectsInSet` | 4096 | 500 |
| `maxConcurrentRequests` | 10 | 4 |
| `maxConcurrentUpload` | 10 | 4 |
| `maxSizeUpload` | 250000000 | 50000000 |
| `maxSizeRequest` | 10000000 | 10000000 — right |
| `collationAlgorithms` | `i;ascii-numeric`, `i;ascii-casemap`, **`i;octet`** | the third was guessed as `i;unicode-casemap` |
| `maxMailboxesPerEmail` | 1000 | 10 |
| `maxSizeMailboxName` | 490 | 490 — right |
| `emailQuerySortOptions` | 14 entries, including `emailstate`, `spamScore`, `snoozedUntil` | 5 entries |
| capabilities offered | core, mail, **contacts**, **submission**, and a vendor URI (masked e-mail) | core and mail only |
| `downloadUrl` host | **a different host from `apiUrl`** — blob downloads are served from a separate origin | one host for everything |
| session `state` | 54 characters: semicolon-separated `key-value` segments including a 10-hex and a 16-hex run | `sess-0001` |

The connector clamps its page size to `maxObjectsInGet`, so a value eight times
larger than the guess costs nothing. `maxCallsInRequest: 50` matters more: the
"one request per page" design needs 2 and had 16 in the fixture; it has 50.

### Ids and states — the shapes `MAX_TOKEN_LENGTH` was guessed against

| object | recorded shape |
|---|---|
| account id | 9 characters, `u` + 8 hexadecimal |
| mailbox id | **3 characters** from `[A-Za-z0-9-]` |
| Email id | 12 characters from `[A-Za-z0-9]` |
| thread id | 12 characters from `[A-Za-z0-9]` |
| blob id | 41 characters, `G` + 40 lower-case hexadecimal |
| `Email/get` `state`, `Email/changes` `oldState` / `newState` | **`J` followed by decimal digits — three to four characters in every observation** |
| `Email/query` `queryState` | the Email state with `:0` appended |

**`MAX_TOKEN_LENGTH = 200` is settled and enormously safe.** The longest server
state this account produced was four characters; the constant is fifty times
that. The one thing to keep in mind is that the *session* state is 54 characters
and a different kind of string — it is never stored as a cursor, so the constant
does not apply to it.

**`Mailbox/get` and `Email/get` answer the same counter.** Both answered the same state
value in the same second. This server keeps one account-wide state number rather than
one per object type. Nothing in the connector depends on that, and nothing
should: RFC 8620 §5.1 scopes a state to its object type.

### The result reference — the design claim, settled

`Email/query` and `Email/get` were sent as two method calls in **one HTTP
request**, with `Email/get` taking `"#ids": {"resultOf": "q0", "name":
"Email/query", "path": "/ids"}`. The server answered both, in one response, with
`Email/query@q0` followed by `Email/get@g0` and the ids resolved. The same held
for `Email/changes` chained into `Email/get` on `/created`.

**One page is one request. W11's central design claim holds on a real server.**

### `Email/changes`

- **It is account-wide, and this is not theoretical.** A single `Email/changes`
  over an old state returned four created ids whose messages lived in **three
  different folders** — Inbox, Archive and Sent. A client that trusts
  `Email/changes` to be folder-scoped ingests the company's own outgoing mail as
  customer feedback. The connector's `mailboxIds[<watched>] === true` check is
  what stands between it and that, and the recording is the proof it is needed.
- **`hasMoreChanges: true` is real**, observed by capping a change window with
  `maxChanges: 1`.
- **A page can report `hasMoreChanges: true` with an empty `created` list.** One
  observation returned `created: []`, `updated: ["…"]`, `hasMoreChanges: true`.
  The connector's rule that an empty page does not end a run was reasoned from
  the RFC; it is now measured.
- **`newState` is not the chained `Email/get`'s `state`.** A capped window
  answered a `newState` part-way through the change log while the `Email/get`
  in the same response answered the account's **current** state — thirty change
  numbers further on, not the end of the window. Taking the next cursor from `Email/get` would skip every change
  between the two and lose them permanently. The connector reads
  `Email/changes.newState` and only that; the test helpers now do the same, and
  `changes-1.json` reproduces the divergence so a regression cannot pass.
- **`cannotCalculateChanges` is real and cheap to reach.** A `sinceState` the
  server does not recognise answers, at **HTTP 200**:
  `["error", {"type": "cannotCalculateChanges", "description": "invalid sinceState"}, "c0"]`
  followed by `["error", {"type": "invalidResultReference"}, "g0"]` for the
  chained call — exactly the pairing the previous fixture guessed, including the
  guess about what happens to a reference whose target failed.

### `Email/query`'s response

The server echoes back more than the RFC lists: alongside `queryState`,
`canCalculateChanges`, `position` and `ids` it returns `total`,
`collapseThreads`, and the `sort` and `filter` it was given. **`total` comes
back even when the request sets `calculateTotal: false`.** Nothing in the
connector reads any of it, but a parser that rejects unknown keys would break,
so the fixtures carry them.

### The messages

| what | recorded |
|---|---|
| `receivedAt` | `Z`, whole seconds, on every message. The previous guess was right |
| `sentAt` | **carries a UTC offset, not `Z`** — `+03:00` and `-04:00` both observed. It is a `Date`, not a `UTCDate` (RFC 8621 §4.1.2), and the recording confirms servers use the offset |
| `textBody` part | **ten keys, not five**: `partId`, `blobId`, `size`, `name`, `type`, `charset`, `disposition`, `cid`, `language`, `location` |
| `charset` | mixed case in one account: `utf-8`, `UTF-8`, `us-ascii`. Any comparison must fold case |
| `preview` | present and non-empty on every plain-text message; whitespace runs collapsed to single spaces; **capped at 200 characters** (190 and exactly 200 measured) |
| `messageId` | a list. Two shapes seen: a UUID at the sending host, and `<digits>.<hex>.<digits>@<internal host>` |
| `mailboxIds` | every observed message was in exactly one mailbox |
| `isTruncated` | **`true` observed**, by sending `maxBodyValueBytes: 64`: the value is cut mid-sentence and `isEncodingProblem` stays `false`. The connector stores the truncated text and does not flag it |
| `notFound` | `Email/get` with an unknown id answers HTTP 200, an empty `list` and the id in `notFound` |

### The mailbox list

Eight folders, all English, all capitalised:

| name | role |
|---|---|
| Inbox | `inbox` |
| Archive | `archive` |
| Drafts | `drafts` |
| Scheduled | `scheduled` |
| Sent | `sent` |
| Spam | **`junk`** |
| Support | **`null`** |
| Trash | `trash` |

Three roles the old fixture did not have: `drafts`, `scheduled`, and `junk` —
note that the folder named `Spam` carries the role `junk`, so role and name do
not track each other. The `Support` folder was created by the account owner and
has **`role: null`**, which is exactly why the connector resolves the watched
folder by *name*: a user-created folder has no role to match on.

**Exact-name matching worked.** The credential named `Support` and the list held
`Support`; the case-insensitive fallback was never reached, so it remains
untested against reality.

### The error bodies

This is where the recording changed the most, and it has its own section below.
What was recorded, positively:

| condition | status | body |
|---|---|---|
| a bearer token the server will not accept | **401** | `text/plain`, one line, with a `WWW-Authenticate: Bearer resource_metadata="…"` header |
| a JSON body that is not a JMAP Request | **400** | `{"status":"400","trace_id":"ti_…","title":"Bad Request","detail":"Not a valid JMAP request (no 'using' array)"}` |
| a body that is not JSON at all | **400** | the same shape, with a parser message that names a server-side source file and line |
| a capability the server does not implement | **400** | the same shape **plus** `"type": "urn:ietf:params:jmap:error:unknownCapability"` |
| an `accountId` the server does not know | **200** | `["error", {"type": "accountNotFound", "description": "Not a valid accountId"}, "m0"]` |

## What the recording falsified

The most valuable part of this exercise. Four assumptions were wrong.

**1. The 401 body is not JSON.** The fixture carried an RFC 7807 document. The
server answers `content-type: text/plain` and a single line:
`Invalid Authorization bearer parameters, not valid format`. It answers exactly
that for a token that is malformed **and** for one that is well-formed but
unknown — probed with a token of the same length and alphabet as a real one,
generated for the probe. The server does not distinguish the two cases in the
body, so nothing can be inferred from the text. **`error-unauthorized.txt`**
holds the recorded line, and it carries a `.txt` extension because it is not
JSON: the App Store set already keeps `page-depth-exceeded.txt` for the same
reason. A file named `.json` that is not JSON is a trap laid for whoever reads
it next.

**2. Request-level errors are not RFC 7807 documents.** They are JSON, but the
server's own shape: `status` is a **string** (`"400"`, not `400`), there is a
`trace_id`, and there is **no `type` member at all** except for
`unknownCapability`. In particular the documented `urn:ietf:params:jmap:error:notRequest`
type — which `error-not-request.json` was built around — **never appeared**. A
request with no `using` array comes back as a plain `"Bad Request"` with a
`detail` and no type. The fixture now carries the recorded body.

**3. There is no 404.** This is the one with a consequence. The connector maps
`404 → Misconfigured` for "no JMAP session resource at that URL", and that
branch **cannot fire on this server**:

- `<session-url>/<nonsense>` answered **HTTP 200 with the complete session
  document**. The extra path segment is ignored.
- Three other unknown paths on the API host answered **HTTP 302** to a
  documentation page, with an nginx body — recorded in
  `error-wrong-path-redirect.txt`.

Laravel's HTTP client follows redirects by default, so a user who pastes a
slightly wrong session URL used to get `MalformedResponse` — "The platform
returned a response this connector could not parse", which blames the mail
provider for the one mistake the user is most likely to have made themselves.
The 404 branch that was supposed to catch it read as handled and was not.

**This is now fixed, and the fix is not the status code.** The status code
cannot be the discriminator, because `<session-url>/<nonsense>` answers 200 with
a document that is completely usable — that case is harmless and still works.
The shape is the discriminator: RFC 8620 §2 requires `capabilities`, `accounts`
and `apiUrl`, so a body without them is not a session resource whatever answered
it, and the connector now reports `Misconfigured` for it. A 3xx the client could
not resolve maps to `Misconfigured` too, where it used to map to `Unreachable`
and cost five queue attempts. Redirects are still **followed**: RFC 8620 §2
makes `/.well-known/jmap` an autodiscovery path servers are expected to redirect
from, so refusing 3xx outright would break conforming servers to catch a case
the shape check already catches.

`ConnectorFailure` did not grow, nothing thrown is built from a response body,
and four tests in `EmailConnectorTest` pin the behaviour — including the one
that proves the harmless stray-suffix case did not become an error.
`error-not-found.json` is kept because another JMAP server may well answer 404,
but on this server that branch is dead code.

**4. The core-capability numbers were low by up to 5×**, and
`collationAlgorithms` named an algorithm this server does not offer. Nothing
broke — the connector clamps rather than trusts — but every number in
`session.json` was wrong and none of them had been marked as more than a guess.

## Still inferred, and why the recording could not reach it

| what | why it is still a guess |
|---|---|
| **403** and its body | never provoked. A trial account's own token reads its own account; there was no second account for it to be refused, and manufacturing one was out of scope. `error-forbidden.json` now follows the server's **recorded** error-document shape, but its status code and wording are invented |
| **429** and its body | **deliberately not provoked.** Exhausting a live service's rate limit to photograph the error is abuse of somebody else's server, so it was not done. `error-rate-limited.json` is invented in the recorded shape. The per-account request budget is likewise unpublished, and `config/connectors.php` should stay at the conservative end |
| a method-level **`serverFail`** | never provoked — the server did not fail. `error-method-server-fail.json` is invented, in the shape `accountNotFound` was recorded in (`type` plus `description`), which is at least the right family |
| **`preview` on an HTML-only message** | **not settled — the account held no HTML-only message.** Every message in it carried a `text/plain` alternative. This was item 5 of the handover list and it remains open: `page-1.json`'s second message is authored, not recorded, and if a real server omits `preview` for an HTML-only message that branch falls through to subject-only |
| a message with **no display name**, a message in **two mailboxes at once**, a message with an **empty text body** | none occurred. All three branches are exercised by authored messages inside the recorded envelope |
| **localised or lower-case mailbox names** | this account's folders are English and capitalised, so the case-insensitive fallback was never reached. Whether a Turkish account answers `Gelen Kutusu` is still unknown, and the fallback is still there on that suspicion |
| **how often a real account hits `cannotCalculateChanges`** | half settled. The error is real and answers instantly for a state the server does not recognise, so the recovery branch is not hypothetical. But nothing observed says how long a *valid* state stays answerable, and that is the number that decides whether the bounded recovery below is acceptable |
| **whether another JMAP server answers 404** | the connector's 404 branch is kept for servers that are not this one. Untested against any of them |

## What the live recording was asked to settle

The previous version of this file left a priority-ordered handover list. Here it
is, marked.

1. **The failure statuses.** — **Settled for 401 and 400, falsified for 404,
   still open for 403 and 429.** 401 and 400 are recorded, including bodies that
   are nothing like what was assumed. 404 does not exist on this server. 403 was
   unreachable and 429 was off limits.
2. **Whether the result reference works in one request.** — **Settled: yes.**
   One HTTP request, both method responses, ids resolved. W11 does not reopen.
3. **The real `state` format and length.** — **Settled.** `J` plus decimal
   digits, three to four characters. `MAX_TOKEN_LENGTH = 200` is safe by a
   factor of fifty.
4. **The real core-capability values.** — **Settled, and all of them were
   wrong.** `maxObjectsInGet` is 4096, not 500.
5. **Whether `preview` is really there for an HTML-only message.** — **Not
   settled.** No such message existed in the account. Still inferred.
6. **A real mailbox list.** — **Settled for this account.** Eight folders, three
   new roles, a user-created folder with a null role, exact-name matching
   confirmed. Localisation and the case-insensitive fallback still untested.
7. **What `cannotCalculateChanges` costs in practice.** — **Half settled.** The
   error is real, recorded verbatim, and pairs with `invalidResultReference` on
   the chained call exactly as guessed. Its frequency in normal operation is
   still unknown.
8. **`receivedAt` precision and rendering.** — **Settled.** `Z`, whole seconds,
   on every message. And a bonus: `sentAt` carries an offset instead, which no
   fixture had shown.

## What is authored inside the recorded envelope

The watched folder was **empty**. `Email/query` on `Support` returned `total: 0`
both inside and outside the 30-day cold-start window, so no page of real
customer mail exists to record. The non-empty page in the capture came from the
Inbox and held the account's own welcome mail.

So: the response envelopes here are recorded, and the messages placed inside
them are written. That covers

- which folder each message sits in, including the one in `Archive` that proves
  the account-wide exclusion,
- the HTML-only message, the message with no display name, the message in two
  folders and the message with an empty body,
- every subject, body value, preview, display name and sender address,
  plus every Message-ID,
- every id, blob id, thread id and state value.

Everything else — key sets, capability numbers, id lengths and alphabets, state
format, the `Email/query` echo fields, the ten-key body part, `receivedAt`
against `sentAt`, the preview cap, the `newState` divergence, the error bodies —
is the recording.

## How the connector maps a message

| `ConnectorItem` | from |
|---|---|
| `externalId` | the `Email` object's `id` — server-assigned, stable, unique per account, 12 characters on the recorded server |
| `author` | `from[0].name` when present, else the **local part** of `from[0].email`. Never the whole thing: `IngestionRunner::maskPii` rewrites every substring shaped like a sender address into `[email]`, so passing it through would put the literal string `[email]` in every author column and throw the sender's identity away |
| `body` | **`subject` + `"\n\n"` + text** when both are present; whichever exists when only one is; the message is **skipped** when neither is. "Text" means the `text/plain` parts' `bodyValues`, or `preview` when the message is HTML-only |
| `sourceUrl` | `null`. JMAP has no canonical web URL for a message — `downloadUrl` serves the raw blob, from a different host entirely on the recorded server, and is not a page a person can open |
| `publishedAt` | `receivedAt`, kept **with its offset**: `IngestionRunner` writes `toIso8601String()`, because `toDateTimeString()` drops the zone and has already put a row seven hours off the real instant once (`docs/LESSONS.md`) |
| `rating` | `null`. E-mail carries no rating, and `ConnectorItem::$rating` is nullable for exactly this case |
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

`Email/changes` is account-wide (RFC 8621 §4.3) — and the recording caught it
returning created ids from Inbox, Archive and Sent in one response. So every
fetched message is checked against `mailboxIds[<watched id>] === true` before it
becomes an item. `changes-1.json`'s fifth message lives in `Archive` only and
exists solely to prove the exclusion.

### Why the mailbox is resolved by name, and why that costs two extra requests

The credential names the mailbox (`"Support"`) rather than carrying its id,
deliberately: ids are per-account opaque strings — **three characters** on the
recorded server — and a value a user pastes into a form must not encode one.
Resolving it means a session `GET` plus a `Mailbox/get`, both memoised for the
run, so "one request per page" is honestly **one request per page plus two per
run**.

`Mailbox/query`'s `name` condition is a substring match (RFC 8621 §2.3), so
asking for `Support` could silently select `Support Archive`. The connector
therefore fetches the whole list with `Mailbox/get { ids: null }` and matches
the name itself: exact first, case-insensitive as a fallback, and
`Misconfigured` when nothing matches, because a mistyped mailbox name is the
most likely configuration error on this channel and it must not present as "no
feedback yet". The recorded account confirms the exact match works and leaves
the fallback untested.

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
  `/created`. `hasMoreChanges` becomes `hasMore`; **`newState`** becomes the
  next token — never the chained `Email/get`'s `state`, which the recording
  showed is the account's current state and not the end of the window.
- **`cannotCalculateChanges`:** a normal branch, not an exception — fall back to
  the full `Email/query` and take a fresh state.
- The token is written to `integrations.sync_cursor` by `IngestionRunner` at the
  **end** of a run, never mid-run. A run that dies on page 3 leaves the stored
  token where it was and the next run walks the same ground again; invariant
  **I2** — `UNIQUE (integration_id, external_id)` — turns the re-fetch into zero
  new rows.
- **The runner's `maxPagesPerRun` is not a stop condition of this connector.**
  `hasMore` is `hasMoreChanges` and nothing else. Reporting `hasMore=false` on
  reaching the runner's cap would tell the runner the run completed and let it
  store a position it never reached (`docs/LESSONS.md`).

**The cold start is bounded, and so is the recovery.** Anything received longer
ago than `initial_lookback_days` is **never read** — this is not a full backfill.
The recovery from `cannotCalculateChanges` runs the same bounded query, which
leaves one gap worth naming: a message *moved* into the watched mailbox after
arriving long ago has an old `receivedAt`, so a recovery would not see it, while
an ordinary `Email/changes` run would. The recording did not close this: it
showed the error is real and instant for an unrecognised state, but not how long
a valid state stays answerable.

## Not covered here at all

- **No fixture carries an attachment's content**, only `hasAttachment`. The
  connector never downloads a blob, and the recorded `downloadUrl` points at a
  different host, which is one more reason not to start.
- **No fixture carries `to`, `cc` or `bcc`.** They are deliberately absent from
  the requested `properties` — they are the company's own staff, they carry no
  analytical signal, and leaving them out is spec §8 data minimisation applied
  one step earlier than `IngestionRunner`'s masking.
- **Push (RFC 8620 §7) is not used at all.** This connector polls, even though
  the recorded session advertises an `eventSourceUrl`.
- **No fixture reproduces a message moved into the watched mailbox long after it
  arrived.** See the cursor gap above.

## The files

| file | what it is |
|---|---|
| `session.json` | the recorded session resource: five capabilities, the real core numbers, a separate download host, a 54-character state. Redacted: user name, account id and name, every host |
| `mailboxes.json` | the recorded `Mailbox/get`: eight folders with real names and roles, ids of the recorded three-character shape. `Support` is the watched one and carries `role: null`; `Archive` and `Sent` are what the account-wide `Email/changes` must not ingest |
| `page-1.json` | the cold start: `Email/query` + `Email/get` in one recorded response, including the echoed `sort`, `filter`, `total` and `collapseThreads`. Three authored messages — one plain text, one **HTML-only** which must fall back to `preview`, one with a **null display name** living in two mailboxes at once |
| `changes-1.json` | `Email/changes` with `hasMoreChanges: true` and a non-empty `updated`. **Its `Email/get` answers a later state than `newState`**, reproducing the recorded divergence. The fifth message is in `Archive` only and must be excluded |
| `changes-2-last.json` | `hasMoreChanges: false` — the last page. The sixth message has an **empty text body** and a non-empty subject, so it is ingested subject-only; the seventh is ordinary |
| `changes-none.json` | nothing changed: empty `created`, `newState` equal to `sinceState`, `hasMoreChanges: false`. Recorded verbatim from a change request over the current state |
| `changes-cannot-calculate.json` | **recorded.** The stale-state branch, `description` included, with `invalidResultReference` on the chained call |
| `page-recovered.json` | the full query the connector falls back to after that error, with a fresh state |
| `error-unauthorized.txt` | **recorded, and not JSON** — hence the extension. The single line of `text/plain` a 401 returns |
| `error-forbidden.json` | **inferred.** Never provoked; written in the server's recorded error shape |
| `error-not-found.json` | **inferred, and dead on this server.** Kept for JMAP servers that do answer 404 |
| `error-rate-limited.json` | **inferred, deliberately.** Provoking a real 429 would be abuse |
| `error-not-request.json` | **recorded.** The real 400 for a JSON body with no `using` array — no `type` member, `status` as a string |
| `error-method-account-not-found.json` | **recorded.** `accountNotFound` with the server's `description`, paired with `invalidResultReference` |
| `error-method-server-fail.json` | **inferred.** The server did not fail |
| `error-wrong-path-redirect.txt` | **recorded.** The nginx body behind the 302 that an unknown path answers instead of a 404 |

States chain strictly, in the recorded `J`+digits format: the page-1 state, then
the state `changes-1` reports as `newState`, then `changes-2-last`'s (which
`changes-none` returns unchanged), with a separate state for what a recovery
establishes. The cursor tests depend on that chain, and read it from
`Email/changes.newState` — not from `Email/get`.

## Writing assertions against these files

Derive expectations from the fixture at run time — `Tests\Support\PlatformFixture`
exists for this. Do not hard-code a message's id, sender or text into a test:
the content here is replaceable by design, and an assertion on a particular
message proves nothing about the connector.

## Two copies

`backend/tests/Fixtures/platforms/email/` holds a byte-identical copy, for the
mount reason described in `backend/config/connectors.php`: the dev compose stack
bind-mounts only `../backend`, so `contracts/` does not exist inside the
container. `backend/tests/Feature/Ingestion/PlatformFixtureParityTest.php`
asserts the two directories agree, byte for byte, and that this README exists.
