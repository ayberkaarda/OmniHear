# W12 — social connector (Mastodon hashtag timeline)

The sixth and last channel of spec §2. Written before dispatch.

## Why Mastodon, and what it costs honestly

The test that eliminated Gmail in W11 was the **credential model**, not the wire
protocol: a connector whose setup reads "re-consent weekly" cannot sit beside
Zendesk's "paste an API key". Applied to the social candidates:

- **X/Twitter** — no free tier at all; reads are billed per resource. Anyone who
  clones this repository cannot read a single post without paying. Out.
- **Reddit** — since mid-2026 every developer needs explicit approval and
  commercial use is paid. "Fill in a form and wait" is not self-serve. Out.
- **Bluesky** — free and credential-less, and product-wise the best fit
  (`mentions` filter, plain-text `text`), but `searchPosts` answered **403** from
  this network while `getProfile` answered 200 on the same host. Cause unknown;
  reopen it if it answers 200 from elsewhere.
- **YouTube comments** — a free non-expiring API key, but it needs a Google Cloud
  project. Sound second choice.
- **Discourse** — easiest of all, but a forum, not social media. It does not
  satisfy the word in spec §2.
- **Mastodon** — no account, no credential, verified live: `GET
  mastodon.social/api/v1/timelines/tag/<tag>?limit=2` answers **200** with
  rate-limit and `Link` headers.

**The honest cost:** two of the six candidates have closed free self-serve access
entirely, so this channel watches a **hashtag**, not mentions of a brand. The
README says so plainly, and the landing copy changes from "Social media mentions"
to hashtags. A product claim the code does not implement is worse than a narrower
claim it does.

Mastodon is also a **protocol, not a vendor**: `instance_url` accepts any
compatible server (Mastodon, Akkoma, GoToSocial), which is the same reason JMAP
beat Gmail.

## What does not change

`PlatformConnector`, `ConnectorPage`, `ConnectorItem`, `ConnectorLimits`,
`SyncCursor` and `IngestionRunner` are fixed. `social` already exists as a
platform at every layer — `Integration.php:22`, `integration.models.ts:12`,
`domain-labels.ts:75`, with its Turkish translation already filled — so the enum
is not touched.

## Settings — and no credentials

Two settings, no credential: `instance_url` (validated as an https URL, the rule
`session_url` already uses) and `hashtag`. The hashtag reaches a URL path, so it
is **whitelisted, not escaped**: `/^[\p{L}\p{N}_]{1,100}$/u`, the same discipline
as Trustpilot's 24-hex id and Google Play's package name.

A connector with no credential is not new — App Store has none either.

## Cursor

`SyncCursor::$token` only, the Zendesk shape.

- **Cold start:** `?limit=N`, the newest page, `hasMore: false`. No day-based
  lookback is needed because the page size is itself the bound.
- **Later runs:** `?min_id=<token>&limit=N`. `hasMore = count(items) === N` —
  Trustpilot's short-page rule.
- Token is the largest id on the page. Ids are opaque strings by Mastodon's own
  guidance; never parse or compare them numerically. They fit `sync_cursor
  varchar(255)`.
- `IngestionRunner` promotes at the end of a run, never between pages.

## Mapping

| feedback | from |
|---|---|
| `external_id` | the status `id` |
| `author` | `display_name`, falling back to `username` — **never `acct`**. For a remote account `acct` is `user@domain`, and `IngestionRunner::maskPii` rewrites anything address-shaped to `[email]`, so passing it throws the name away. Its masking inside `raw_payload` is correct and should be asserted |
| `body` | `content` with its HTML stripped — `</p>` and `<br>` become line breaks, then tags out, then entities decoded. Skip when empty |
| `published_at` | `created_at`, offset preserved (`toIso8601String()`; `toDateTimeString()` has already put a row seven hours off once) |
| `source_url` | `url`, falling back to `uri` |
| `rating` | null |

A status with `reblog !== null` is a boost, not an original post: **skip it**, the
same judgement that skips a Trustpilot review with no text.

## Errors

`401` (the instance disabled public preview) → `InvalidCredentials`; `404`/`422`
→ `Misconfigured`; `429` → `RateLimited`; `5xx` → `Unreachable`. Nothing thrown
is ever built from a response body — `ConnectorFailure`'s six fixed sentences.
`rate_limit` sits well under the documented 300 per 5 minutes.

**`403` is missing from that table and it matters.** The connector track kept to
the contract's letter and left it in the `default → Unreachable` arm, which means
a suspended or defederated instance is retried five times before it gives up.
`Unreachable` is retryable by design; a 403 here is a standing decision by the
server, not a blip. Map it to `Misconfigured` when this contract is next opened —
recorded rather than fixed silently, because it changes retry behaviour and the
track was right not to take that on its own.

## Live recording — the easiest in the project

This channel needs no account, so the recording happens inside the track:
`mastodon.social`, a neutral hashtag, `?limit=40`. Discipline is the App Store
precedent — **envelope real, content synthetic**: `account.*` becomes
`poster-NN`, every `url`/`uri`/instance host becomes something under
`example.invalid`, and the bodies are written for the repository. What stays real
is behaviour: the `Link` header shape, the id format and width, `created_at`
rendering, the rate-limit headers, the sanitised-HTML shape of `content`.

A test walks every fixture and refuses anything outside `example.invalid` and any
display name that is not `poster-NN`, exactly as the App Store, Trustpilot and
email suites do. That test is why the promise in the README is a fact rather than
a claim.

**What a recording cannot settle**, and so stays in the inferred table: the 401
body (no instance with public preview disabled was found), the 429 body (the
quota was not deliberately exhausted), and when exactly 422 is returned.

## Known limits, for the README

Watches a hashtag, not mentions. Federation reach depends on the instance — a
small server sees less of the network than `mastodon.social`. An instance with
public preview disabled needs a token, which this connector does not implement;
it surfaces as `InvalidCredentials`.
