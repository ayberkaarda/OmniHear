# Trustpilot business-unit review fixtures — provenance

**Nothing here was captured. Every byte is synthetic.**

There is no Trustpilot business account behind this project. These files were
written from Trustpilot's published Business Units API documentation so that
`TrustpilotConnector` and the whole ingestion path can be exercised with
`Http::fake()`. They contain **no real reviewer, no real review, no real
business unit id, no real company name and no real credential** — the reviewers
are `reviewer-01` … `reviewer-05`, the business is `Example Company` on
`example.invalid` (a domain RFC 2606 reserves so it can never resolve), and
every id is a made-up 24-character hex string.

That is a policy, not an accident. It is the same rule the App Store fixtures
were rewritten to satisfy (decision D-06, `docs/PROGRESS.md`): **no real
person's data enters a fixture.** This repository is public and paid for that
mistake once with a full history rewrite (`docs/LESSONS.md`).

`backend/tests/Unit/Connectors/TrustpilotConnectorTest.php` asserts the promise
rather than only stating it: a test walks every fixture and refuses any display
name that is not `reviewer-NN`, any id that is not synthetic hex, and any
`referralEmail` that is not `null`.

## Verified against documentation vs inferred

None of this was measured against a live account, so the distinction matters
more here than for a captured fixture. **Anything in the second table is a place
to look first if a real account behaves differently.**

### Taken from the documentation

| what | detail |
|---|---|
| endpoint | `GET /v1/business-units/{businessUnitId}/reviews` on `https://api.trustpilot.com` |
| authentication | the API key travels in an **`apikey` request header**. It is not a query parameter and must never be made one — a key in a query string is written into every access log between here and Trustpilot |
| paging | `page` (1-based) and `perPage` query parameters |
| `perPage` ceiling | 100 |
| ordering | `orderBy=createdat.desc` gives newest-first. The parameter is spelled `createdat`, all lower case, not `createdAt` |
| response envelope | a JSON object with a `reviews` array |
| review field set | `links`, `id`, `stars`, `title`, `text`, `language`, `location`, `createdAt`, `updatedAt`, `experiencedAt`, `referralEmail`, `referenceId`, `companyReply`, `isVerified`, `numberOfLikes`, `status`, `reportData`, `complianceLabels`, `countsTowardsTrustScore`, `countsTowardsLocationTrustScore`, `invitation`, `source`, `consumer`, `businessUnit` |
| `stars` | integer 1–5 |
| `title` / `text` | separate fields — a short headline and the free-text body |
| `consumer` | `{ id, displayName, displayLocation, numberOfReviews, links }`; `displayName` is the only name Trustpilot publishes for a reviewer |
| `companyReply` | `{ text, createdAt }` or `null` — the business's public answer, not the customer's words |
| timestamps | ISO 8601, UTC, `Z` suffix |
| business unit id | a 24-character hexadecimal string |
| auth failure | **401** for a missing or unrecognised key |
| permission failure | **403** when the key is valid but may not read the requested business unit |
| unknown business unit | **404** |
| rate limiting | **429** |

### Inferred, and worth re-checking against a live account

| what | why it is a guess |
|---|---|
| the exact wording and shape of the error bodies (`{"message": "…"}` in all four `error-*.json` files) | **the connector reads only the status code**; the bodies exist so that no test can pass against an empty one. Trustpilot's error envelope is not documented field by field |
| that an **empty page ends the stream** | this is the page-based reading — a short page (`count(reviews) < perPage`) means there is nothing behind it, and zero is short. It is *not* what the App Store feed does, where empty pages appear transiently (`docs/LESSONS.md`). No transient-empty behaviour is documented for Trustpilot, so it is treated as an ending. The failure mode if that is wrong is bounded rather than lossy: `IngestionRunner` refuses to promote a watermark on any run that saw an empty page, so the next run walks the same ground again and invariant I2 turns the re-fetch into zero new rows |
| that the response carries **no total count or next-page link this connector could use** | the documentation shows the review array as the payload; the paging position is the caller's `page` number. If a live account returns a `links[rel=next-page]`, that would be a better stop condition than a short page and the connector should be revisited |
| `https://www.trustpilot.com/reviews/{reviewId}` as `sourceUrl` | the human-facing permalink, not an API-documented field. The review's own `links` entries point back at the API, which is useless to someone opening the item from the inbox — the same call `ZendeskConnector` makes for its agent deep link |
| exact key ordering and the presence of every optional key on every review | the field *set* is documented; which keys a real response omits on a given review is not. The fixtures carry the full set, with `null` where a value is absent |
| `"source": "Organic"` / `"Invitation"` as the value vocabulary | plausible from the documented distinction between organic and invited reviews; not read by the connector |
| the per-minute request budget | not published as a number. `config/connectors.php` should be set at the conservative end (a shared file, not this workstream's) |

### Not covered here at all

- No fixture reproduces a **reported or removed** review (`status` other than
  `"active"`, non-null `reportData`). The connector does not filter on `status`,
  because the documented behaviour of the endpoint for such reviews is unclear.
  If a live account returns them, that is a filtering decision to make then.
- No fixture carries an email address. The runner masks addresses out of
  `body`, `author` and `raw_payload` on every platform (`IngestionRunner::maskPii`),
  and that path is already covered by the Zendesk fixtures.

## How the connector maps a review

| `ConnectorItem` | from |
|---|---|
| `externalId` | `id` |
| `author` | `consumer.displayName`, else `null` |
| `body` | **`title` + `"\n\n"` + `text`** when both are present; whichever exists when only one is; the review is **skipped** when neither is |
| `sourceUrl` | `https://www.trustpilot.com/reviews/{id}` (inferred, above) |
| `publishedAt` | **`createdAt`**, not `updatedAt`: an edit or a company reply must not move a review forward in the inbox or the trend charts |
| `rating` | `stars`, already the product's 1–5 scale. Anything outside 1–5, or non-numeric, becomes `null` |
| `rawPayload` | the whole review object |

### Why `title` and `text` are joined rather than one chosen

Both carry meaning and the analyzer sees `feedbacks.body` and nothing else. The
headline is frequently where the sentiment lives (`"Kargo beklediğimden hızlı
geldi"`) and the body is where the reason lives. Dropping either throws away
signal that the sentiment and category analysis is built on. The decision is
recorded in `TrustpilotConnector`'s class docblock and asserted by a test.

## The cursor model

Newest-first and page-based — `AppStoreConnector`'s shape, not Zendesk's.

- `SyncCursor::$page` is the intra-run pointer.
- `SyncCursor::$watermark` is the inter-run pointer: the highest `createdAt`
  already ingested. A run stops as soon as it reaches an item at or below it,
  which is the incremental fetch spec §6.1 requires.
- `SyncCursor::$pending` accumulates during the run; `IngestionRunner` promotes
  it onto `watermark` only when the run completed. The connector never promotes
  mid-run — on a newest-first feed a watermark advanced after page 1 makes every
  item on page 2 compare as already-seen (`docs/LESSONS.md`).
- **The runner's `maxPagesPerRun` is not a stop condition of this connector.**
  Unlike the App Store feed, Trustpilot publishes no page-depth ceiling, so
  reporting `hasMore=false` on reaching the cap would tell the runner the run
  completed and let it promote a position it never reached. The cap stays the
  runner's runaway-loop ceiling; a capped run resumes from the cursor's page
  number.
- Re-fetching inside the window is expected and cheap: `UNIQUE (integration_id,
  external_id)` — invariant **I2** — turns it into zero new rows.

## The files

| file | what it exercises |
|---|---|
| `page-1.json` | three reviews, newest-first. A full page when the connector is built with `perPage: 3`, so the run continues. Review 3 has a `title` and an empty `text` |
| `page-2-last.json` | two reviews — a short page, which ends the run. Review 4 has a `text` and a `null` `title`; review 5 has neither and must be skipped |
| `page-empty.json` | zero reviews — the end of the feed reached exactly on a page boundary |
| `error-unauthorized.json` | the 401 body |
| `error-forbidden.json` | the 403 body |
| `error-not-found.json` | the 404 body |
| `error-rate-limited.json` | the 429 body |

Timestamps run strictly newest-first across `page-1.json` then
`page-2-last.json`, which the watermark tests depend on.

## Two copies

`backend/tests/Fixtures/platforms/trustpilot/` holds a byte-identical copy, for
the mount reason described in `backend/config/connectors.php`.
`backend/tests/Feature/Ingestion/PlatformFixtureParityTest.php` asserts the two
directories agree.
