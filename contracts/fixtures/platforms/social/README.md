# Mastodon hashtag-timeline fixtures — provenance

**The envelope is real. The posts are not.**

These files were built from a live capture of the public Mastodon hashtag
timeline, taken on **2026-09-04**:

```
GET https://mastodon.social/api/v1/timelines/tag/coffee?limit=40
```

No account, no token, no `Authorization` header — that endpoint answers `200` to
anyone, which is the whole reason this channel is Mastodon and not X, Reddit or
Bluesky (`docs/contracts/w12-social-connector.md`). It is also why this is the
first connector since App Store whose fixtures could be recorded rather than
synthesised from documentation.

**The raw capture never entered this repository.** It was written to a directory
outside the working tree, redacted there by a script, audited against the
capture, and only the redacted output was copied in. That sequence is not
ceremony: skipping it once put real review text into this project's git history
and cost a full history rewrite (decision D-06 in `docs/PROGRESS.md`).

## Why the content was replaced

1. **Copyright.** A Mastodon post is its author's work. Nothing in a public API
   licenses a third party to redistribute it, and this repository goes public
   when the project is finished.
2. **Spec §8 (KVKK).** The capture held display names, handles, profile URLs,
   avatar URLs, biographies and image alt text — all direct identifiers.
   Pseudonymising the names alone would not have solved (1).

## What is still the recorded original

Everything that carries behaviour:

| kept | why it matters |
|---|---|
| the status key set and its exact order | `id`, `created_at`, `in_reply_to_id`, `in_reply_to_account_id`, `sensitive`, `spoiler_text`, `visibility`, `language`, `uri`, `url`, `replies_count`, `reblogs_count`, `favourites_count`, `quotes_count`, `edited_at`, `content`, `reblog`, `account`, `media_attachments`, `mentions`, `tags`, `emojis`, `tagged_collections`, `quote`, `card`, `poll`, `quote_approval` |
| `application`, present only on some statuses | the recorded response really is irregular here: it appears on statuses local to the queried instance and is absent on federated ones |
| the account key set and its order, all 33 members | including the ones nothing reads — `indexable`, `hide_collections`, `show_media_replies`, `feature_approval`, `noindex` |
| the id **format**: 18 digits, all numeric, decreasing down a page | the connector treats ids as opaque strings and never compares them numerically; the width is what proves they fit `sync_cursor varchar(255)` |
| the `created_at` rendering `YYYY-MM-DDTHH:MM:SS.mmmZ` | millisecond precision and a `Z` suffix, which is what `toIso8601String()` has to survive |
| the sanitised-HTML shape of `content` | `<p>` wrappers, bare `<br>`, the hashtag anchor `<a href="…" class="mention hashtag" rel="nofollow noopener" target="_blank">#<span>tag</span></a>`, the mention anchor `class="u-url mention"`, and the `&amp;` / `&#39;` entities the server emits |
| the counter fields, booleans and `language` values | `replies_count`, `reblogs_count`, `favourites_count`, `quotes_count`, `sensitive`, `visibility`, and the `null` language a status can carry |
| the `media_attachments` `meta` blocks | `original` / `small` / `focus`, with the recorded widths, heights, `size` strings and `aspect` floats |
| `page-empty.json` | **unmodified capture.** `?min_id=<newest id>` with nothing newer really answers `[]` — and, notably, sends **no `Link` header at all** |
| `error-unauthorized.json` | **unmodified capture.** `{"error":"The access token is invalid"}` |
| `error-not-found.html` | the `<head>` of the real HTML page returned with `404`. Trimmed to its head because the rest is markup the connector never looks at; what it has to prove is that a **non-JSON** body at 404 still maps by status alone |

### The headers, recorded but not stored as files

The connector reads none of them, so they are not fixtures; they are recorded
here because they are the reason this channel is safe to poll.

```
link: <https://mastodon.social/api/v1/timelines/tag/coffee?limit=40&max_id=117207719691607130>; rel="next",
      <https://mastodon.social/api/v1/timelines/tag/coffee?limit=40&min_id=117214018255528536>; rel="prev"
x-ratelimit-limit: 300
x-ratelimit-remaining: 299
x-ratelimit-reset: 2026-09-04T18:25:00.382676Z
```

300 requests per 5 minutes, per IP, on an unauthenticated read. The `rel="prev"`
link is what the `min_id` cursor model follows; the connector builds that query
itself rather than parsing the header, because the header is absent on an empty
page.

## What was synthesised

| field | replaced with |
|---|---|
| every `account.display_name` / `username` | `poster-01` … `poster-09`. One account, `poster-04`, keeps the **empty** `display_name` the capture contained, because the fallback to `username` is a real case |
| every `account.acct` | `poster-NN` for a local account, `poster-NN@remoteN.example.invalid` for a federated one — the address shape that must never reach `author` |
| every `account.id`, status `id`, media id, mention id | synthetic 18-digit numbers, decreasing down each page |
| every host | `social.example.invalid`, `remote1-3.example.invalid`, `files.social.example.invalid`. RFC 2606 reserves `.invalid`, so none of them can resolve |
| every `url` / `uri` / `avatar` / `header` / media / emoji URL | the recorded path shape on those hosts |
| `content` | written for this repository, in the recorded markup |
| `account.note`, `account.fields`, media `description`, `blurhash` | written for this repository |
| `tags[].name` | `omnihear`, `feedback`, `support`, … — the queried hashtag was `coffee`, and the other tags were the posters' own |
| `created_at` values | synthetic, decreasing down each page, in the recorded rendering |

The audit that enforces this runs in two places: in the redaction script, which
refuses to emit a fixture sharing any 12-character window of prose with the
capture, and in
`backend/tests/Unit/Connectors/MastodonConnectorTest.php` ("holds no real
person, host or instance in any fixture"), which walks every file and refuses
any host outside `example.invalid` and any display name that is not `poster-NN`.
The second one is the one that survives into CI.

## What is inferred — not recorded, and not to be trusted against a live instance

| inferred | why it could not be recorded |
|---|---|
| `error-rate-limited.json` (`429`) | the 300-per-5-minutes quota was not deliberately exhausted. The **body** is a guess; the status code and the headers above are recorded |
| `error-unprocessable.json` (`422`) | no request shape was found that this endpoint answers with 422. Mastodon accepted every malformed `min_id` and every unknown tag with `200 []`. Kept in the error table because the contract lists it, and because other Mastodon-compatible servers may be stricter |
| the `401` **scenario** | the body is a real capture, but from `GET /api/v1/timelines/home` on the same instance — an endpoint that requires a token. No instance with public preview switched off was found to record the tag-timeline case, so the mapping "401 → the instance disabled public preview" is an inference over a recorded body |
| the boost in `page-2-last.json` | Mastodon's hashtag timeline **excludes boosts**: `reblog` was `null` on all 40 recorded statuses. The wrapper is a real status with a nested status assembled into `reblog`, and its own `content` is the empty string a reblog wrapper really carries. The skip rule is defensive, for servers that do not filter them |
| `<p></p>` as the empty body in `page-2-last.json` | the capture contained no status whose content stripped to nothing. Mastodon serialises a media-only post with an empty `content`; `<p></p>` was chosen instead so the fixture exercises the **stripping** path rather than an empty-string short circuit |
| `url: null` on the last status of `page-2-last.json` | every recorded status carried a `url`. The fallback to `uri` is documented behaviour for statuses with no local permalink, and it is asserted here rather than left unexercised |
| `card` | `null` on every fixture. Eight of the forty recorded statuses carried a link preview, but the connector never reads it and reproducing it would have meant synthesising a whole second object |

## The files

| file | what it is |
|---|---|
| `page-1.json` | five statuses, newest-first within the page. A **full** page against the page size the tests use (5), so it continues the run. Carries the local account, the remote (`acct` address-shaped) account, a reply with a content warning, the empty `display_name`, and the custom-emoji case |
| `page-2-last.json` | three statuses — a **short** page, which ends the run. A boost, a status whose body strips to nothing, and one ingestable status with `url: null` |

`min_id` walks **forward**, so the two pages are ordered the opposite way round
from the `max_id`-based App Store and Trustpilot fixtures: every id and
`created_at` in `page-2-last.json` is **newer** than every one in `page-1.json`,
because a run fetches the page nearest its stored token first and the page
behind it is the newer one. Within each page the order is newest-first, as the
API returns it.
| `page-empty.json` | `[]`, unmodified capture |
| `error-unauthorized.json` | `401`, unmodified capture |
| `error-not-found.html` | `404`, the head of the real HTML page |
| `error-rate-limited.json` | `429`, inferred |
| `error-unprocessable.json` | `422`, inferred |

## Two copies

`backend/tests/Fixtures/platforms/social/` holds a byte-identical copy, because
`infra/docker-compose.dev.yml` mounts `contracts/` read-only and the connector
config (`backend/config/connectors.php`) loads fixtures from under `tests/`.
`backend/tests/Feature/Ingestion/PlatformFixtureParityTest.php` asserts the two
directories agree; a divergence is a silent trap.

## Writing assertions against these files

Derive expectations from the fixture at run time — `Tests\Support\PlatformFixture`
exists for this. Do not hard-code a status's id, author or text into a test: the
content here is replaceable by design, and an assertion on a particular post
proves nothing about the connector.
