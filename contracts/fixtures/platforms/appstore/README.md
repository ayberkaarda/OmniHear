# App Store customer-review fixtures — provenance

**The envelope is real. The reviews are not.**

These files were captured from the live App Store customer-review RSS feed
(`https://itunes.apple.com/{country}/rss/customerreviews/...`) on **2026-09-02**.
Every review that was in that capture has since been replaced with text written
for this repository. No real person's name, profile URI, review title or review
body remains in any of them.

## Why the content was replaced

1. **Licensing.** Apple's Media Services terms license review content *to
   Apple*. Copyright in a review stays with the reviewer, and nothing in those
   terms licenses a third party to redistribute it. This repository is private
   today and goes public when the project is finished, so republishing captured
   review text was not an option.
2. **Spec §8 (KVKK).** Feedback authors' personal data must be maskable. The
   capture held reviewer nicknames and Apple reviewer profile URIs — direct
   identifiers — and pseudonymising the names alone would not have solved (1).

Rewriting these files does **not** remove the original capture from git history
(commit `24ac570`). That is tracked separately as decision D-06 in
`docs/PROGRESS.md`; a history rewrite is planned before the repository is made
public.

## What is still the recorded original

The fixtures exist to prove how the feed behaves, so everything that carries
behaviour was left byte-for-byte as captured:

| kept | why it matters |
|---|---|
| the `feed` key set and its `author` / `updated` / `rights` / `title` / `icon` / `link` / `id` members | the envelope a parser has to survive |
| the six `link` relations (`alternate`, `self`, `first`, `last`, `previous`, `next`) | the page-navigation shape |
| **50 entries per page** | the measured page size |
| the entry key set and its exact order | `author`, `updated`, `im:rating`, `im:version`, `id`, `title`, `content`, `link`, `im:voteSum`, `im:contentType`, `im:voteCount` |
| every `updated` timestamp, **including the `-07:00` offset** | the offset is the reason `CarbonImmutable::toDateTimeString()` was wrong by seven hours (PROGRESS, 2026-09-02) |
| the `im:rating` distribution | 5★33 4★3 3★2 2★1 1★11 on `page-full.json`, 5★29 4★5 3★2 2★3 1★11 on `page-full-2.json` |
| `im:version`, `im:voteSum`, `im:voteCount`, `im:contentType` | untouched, including the seven non-zero vote pairs |
| the `link` attribute shape on every entry | `rel=related` + `href` |
| `author.label` (empty string on every entry) | a real quirk of the feed |
| `content.attributes.type` (`text`) | — |

`rights` still reads `Copyright 2008 Apple Inc.` because it is part of the
captured envelope, not part of the captured content. It is a field the API
emits; it does not describe the synthetic text next to it.

## What was synthesised

| field | replaced with |
|---|---|
| `author.name.label` | `reviewer-001` … `reviewer-100`, numbered newest-first across both pages |
| `author.uri.label` | `https://example.invalid/tr/reviews/id<n>` — Apple's path shape on a host reserved by RFC 2606, so it can never resolve to a person |
| entry `id.label` | synthetic 11-digit numbers, strictly decreasing in the same order the real ids were, with varying gaps |
| `title.label` | written for this repository |
| `content.label` | written for this repository, consistent with the entry's `im:rating`; mean length 73.7 chars (`page-full.json`) and 75.5 (`page-full-2.json`), from 1 char to ~200; mostly Turkish with a few English reviews, and the emoji-only and embedded-newline cases the capture contained |
| the app id in `id` / every `link` href | `999999999` |

Titles and bodies are globally unique across the two pages, as are the
synthetic ids and reviewer names.

## The files

| file | what it is |
|---|---|
| `page-full.json` | a full page, 50 entries. Captured as `page=3`. The **older** of the two pages (2026-08-26 → 2026-08-27) |
| `page-full-2.json` | a full page, 50 entries. Captured as `page=1`. The **newer** page (2026-08-29 → 2026-08-31), so serving it as page 1 and `page-full.json` behind it exercises the incremental watermark |
| `page-empty-transient.json` | **unmodified capture.** The envelope with `entry` absent — the feed really does answer this intermittently, and it is why an empty page must never end a run. It has no review content, so there was nothing to synthesise; it still carries the real app id, which is the one identifier left in this directory |
| `page-depth-exceeded.txt` | **unmodified capture.** The plain-text body Apple returns with HTTP 400 for `page=11`. The status code is the signal; the body is not JSON |

## Two copies

`backend/tests/Fixtures/platforms/appstore/` holds a byte-identical copy,
because `infra/docker-compose.dev.yml` mounts `contracts/` read-only and the
connector config (`backend/config/connectors.php`) loads fixtures from under
`tests/`. `backend/tests/Feature/Ingestion/PlatformFixtureParityTest.php`
asserts the two directories agree; a divergence is a silent trap.

## Writing assertions against these files

Derive expectations from the fixture at run time — `Tests\Support\PlatformFixture`
exists for this. Do not hard-code a review's id, author or text into a test: the
content here is replaceable by design, and an assertion on a particular review
proves nothing about the connector.
