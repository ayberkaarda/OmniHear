# W11 — email connector (JMAP) contract

Written before dispatch. Track A builds against this; the main thread wires it
afterwards using the W8 recipe (`8558e84`).

## Why JMAP and not Gmail

Both were costed. Gmail REST loses on four measured points, and the fourth is
disqualifying for a project anyone can clone:

- `users.messages.list` returns only `id` and `threadId`, so every message needs
  a second `messages.get` — **N+1 per page**. No connector in this tree has that
  shape and `ConnectorLimits` is reasoned per page.
- ~~`q=after:` accepts a calendar date, not an epoch second.~~ **This was wrong
  and is withdrawn.** Gmail's filtering guide documents passing the value in
  seconds, and `users.history.list` with `startHistoryId` is a real,
  label-scoped cursor that answers 404 when it goes stale — the exact analogue
  of JMAP's `cannotCalculateChanges`. Gmail's incremental story is better than
  this document originally claimed, and better than JMAP's on folder scoping.
- The body arrives as nested base64url `multipart/*` needing a MIME walk,
  charset handling and quote stripping — a class of code with no precedent here.
- A project whose publishing status is "Testing" is issued a **refresh token that
  expires in 7 days**, and `gmail.readonly` is a restricted scope, so leaving
  Testing requires verification. The connector would break weekly for anyone who
  cloned this and pasted their own credentials.

JMAP (RFC 8620/8621) answers all four: `Email/query` chains into `Email/get` in
one request via a `#ids` result reference, `bodyValues` returns the text inline,
`state` plus `Email/changes { sinceState }` is a real cursor, and authentication
is a plain bearer API token. Fixtures derived from an RFC also keep the
provenance README's "inferred" column nearly empty — the honest advantage for a
channel that will never touch a live account.

## What does not change

`PlatformConnector`, `ConnectorPage`, `ConnectorItem`, `ConnectorLimits`,
`SyncCursor` and `IngestionRunner` are **fixed** (W8 contract §2). The connector
fits them or the design is wrong; it does not edit them.

`email` already exists as a platform at every layer — `Integration.php:21`,
`integration.models.ts:12`, `domain-labels.ts:73` — so nothing new is being
introduced into the enum. `IngestionRunner:140` already anticipates an email
channel and `:229` masks addresses in author/body/raw_payload.

## Credentials

Three keys, all strings: `session_url`, `api_token`, `mailbox` (the folder or
label to read; a name, not an id — ids are per-account and a credential a user
pastes must not encode one). Encrypted at rest by the existing `encrypted` cast,
never logged, never echoed back (**I5**). `sync_error` carries no token.

## Cursor

`SyncCursor::$token` only — the Zendesk shape, not a `published_at` watermark.

- First run: `Email/query` with a filter on the mailbox, then `Email/get` for the
  ids, and the response's `state` becomes the token.
- Later runs: `Email/changes { sinceState: <token> }`, then `Email/get` on the
  created ids. `hasMoreChanges` maps to `ConnectorPage::$hasMore`.
- The token is promoted by `IngestionRunner` at the end of a run, never between
  pages — `docs/LESSONS.md` records both halves of that lesson, including that
  `$capped` alone is the wrong test for cutting a run short.
- A `cannotCalculateChanges` error means the server can no longer answer from
  that state: fall back to a full `Email/query` and take a fresh state. Treat it
  as a normal branch with its own fixture, not an exception.

**`Email/changes` is account-wide, not folder-scoped.** RFC 8621 §4.3 takes no
mailbox filter, so the watched folder is enforced client-side by checking
`mailboxIds[<watched>] === true` on each fetched message. Missing this ingests
the company's own Sent and Archive folders as customer feedback. A fixture and a
test must prove the exclusion with a message that lives elsewhere.

**"One request per page" is one request per page plus two per run.** A run also
performs a session `GET` and a `Mailbox/get`, memoised for the run — because the
credential names the mailbox rather than carrying an id (deliberate: an id is
account-specific and a pasted credential must not encode one), and
`Mailbox/query`'s `name` condition is a **substring** match (§2.3), so asking
for "Support" can silently select "Support Archive". Fetch the list and match
the name exactly, preferring an exact match and falling back to a
case-insensitive one — a user who types `inbox` means `Inbox`.

**Cold start.** With no token there is nothing to paginate and `$page` is
excluded, so the first run reads **one page: the newest `page_size` messages,
`hasMore: false`**, bounded by `Email/query`'s `after` filter set to
`now − initial_lookback_days`. Without that bound a mailbox with four years of
mail spends four years of quota on its first sync. The setting mirrors
Zendesk's `initial_lookback_days` in `config/connectors.php`. Anything older
than the window is never read; say so in the README rather than implying a full
backfill.

## Mapping

| feedback | from |
|---|---|
| `external_id` | the JMAP `Email` object's `id` (server-assigned, stable, unique per account) |
| `author` | `from[0].name` when present, else the address' local part — `IngestionRunner` masks a bare address to `[email]`, so passing the raw address throws the display name away |
| `body` | `subject` + `"\n\n"` + the `textBody` part's `bodyValues`; if the message is HTML-only, `preview` rather than a hand-rolled HTML strip. Skip only when **both** are empty. This follows the documented Trustpilot decision for `title` + `text`, and for the same reason: the analyzer only ever sees `feedbacks.body`, and an email's subject is where the sentiment usually lives — "Still no refund" carries the complaint even when the body is one line |
| `published_at` | `receivedAt`, ISO-8601 **with offset** — `CarbonImmutable::toDateTimeString()` drops it and has already put a row seven hours off the real instant once |
| `source_url` | none; JMAP has no canonical web URL. Leave it null rather than inventing one |
| `rating` | null. `ConnectorItem::$rating` is nullable and email carries no rating |

Messages whose text is empty after trimming are **not ingested** — the same rule
Trustpilot applies, for the same reason: a blank row costs a unit of quota.

## Health check

`healthCheck()` performs a JMAP session fetch and reports reachability plus
whether the named mailbox exists. A wrong mailbox name is the most likely
configuration error and it must not present as "no feedback yet".

## Fixtures

`backend/tests/Fixtures/platforms/email/`, mirrored to
`contracts/fixtures/platforms/email/`, with a README separating what is
**documented** (cited to RFC 8620/8621 section numbers) from what is
**inferred** — the same provenance discipline W8 used for Google Play and
Trustpilot. Cover at minimum: a full page, a second page, `Email/changes` with
`hasMoreChanges`, `cannotCalculateChanges`, an HTML-only message, a message with
an empty text body, and a message whose `from` has no display name.

## Testing

- `Http::fake()` **merges** stub callbacks rather than replacing them: a second
  `Http::fake(closure)` leaves the first in charge and the later phase silently
  never runs. One closure driven by a mutable script, with the request count
  asserted. This cost both W8 connector tracks a debugging pass.
- Assertions over ingested rows must not depend on database row order — a
  `pluck()` with no `orderBy` compared by `toBe()` passed alone and failed in the
  suite as recently as W9.
- Test database `test_tmp_w11em`, dropped by explicit name.
