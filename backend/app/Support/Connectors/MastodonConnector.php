<?php

namespace App\Support\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A Mastodon hashtag timeline — the social channel of spec §2.
 *
 * ## What this channel is, stated plainly
 *
 * It watches a **hashtag**, not mentions of a brand. That is a narrower product
 * claim than "social media mentions" and it is deliberate: of the six social
 * candidates surveyed in docs/contracts/w12-social-connector.md, X bills every
 * read, Reddit requires approval before a developer may read anything, and
 * Bluesky's search endpoint answered 403 from this network. Mastodon's public
 * hashtag timeline needs **no account and no credential**, so anyone who clones
 * this repository can run this connector immediately. A claim the code does not
 * implement would be worse than the narrower one it does.
 *
 * Mastodon is a protocol rather than a vendor, so `instance_url` accepts any
 * compatible server (Mastodon, Akkoma, GoToSocial) — the same reason the e-mail
 * channel speaks JMAP rather than Gmail.
 *
 * ## Where the shape came from
 *
 * Unlike every connector since App Store, this one was **recorded live**:
 * `GET mastodon.social/api/v1/timelines/tag/<tag>?limit=40` answers 200 without
 * authentication. The fixtures under tests/Fixtures/platforms/social/ keep the
 * recorded envelope — the `Link` header shape, the 18-digit ids, the
 * `.mmmZ` rendering of `created_at`, the rate-limit headers and the
 * sanitised-HTML shape of `content` — while every account identity, host and
 * post body was rewritten for this repository.
 * contracts/fixtures/platforms/social/README.md separates, field by field, what
 * was recorded from what is inferred. Read it before trusting a detail here
 * against a live instance.
 *
 * ## No credentials — and what invariant I5 still means here
 *
 * There is nothing to leak: the public timeline takes no token, so there is no
 * `Authorization` header, no query-string secret and no cached credential. What
 * survives from I5 is the structural half, unchanged from the credentialed
 * connectors: **nothing thrown here is built from a response body**, every
 * failure is one of ConnectorFailure's six fixed sentences, and this class
 * writes no log lines at all.
 *
 * ## Cursor — the token shape, not the watermark shape
 *
 * `SyncCursor::$token` alone, the way ZendeskConnector carries `after_cursor`.
 * The timeline speaks ids, not timestamps, so a watermark on `created_at` would
 * be a second, weaker encoding of the same position.
 *
 *  - **Cold start** (no token): `?limit=N` — the newest page, and the run stops
 *    there. `hasMore` is false. No lookback window is needed because the page
 *    size *is* the bound on a first sync, and a first sync that walked a busy
 *    hashtag back through its history would spend the tenant's whole analysis
 *    quota before it showed them anything.
 *  - **Later runs**: `?min_id=<token>&limit=N`, which returns the statuses
 *    immediately newer than the token, newest-first within that slice. The run
 *    continues while the page came back full and stops on a short one —
 *    Trustpilot's short-page rule.
 *  - The token is the largest id on the page, which on a newest-first page is
 *    the id of its **first** element. Ids are opaque strings by Mastodon's own
 *    guidance, so nothing here parses them or compares them numerically;
 *    "largest" is read off the ordering the API guarantees, not off the digits.
 *  - An empty page leaves the stored token exactly where it was. Replacing it
 *    with null would restart the next run from the newest page and re-ingest
 *    everything (I2 would absorb it, but the analysis quota would not).
 *
 * `IngestionRunner` promotes at the end of a run, never between pages.
 *
 * ## Decision: the short-page rule counts *statuses*, not mapped items
 *
 * The contract writes this as `hasMore = count(items) === N`. It is applied here
 * to the statuses the API returned, before boosts and empty bodies are dropped:
 * a page of N statuses that happened to be all boosts would otherwise map to
 * zero items, read as a short page, and end a run that had in fact only just
 * started — burying everything behind it. That is the same distinction
 * ConnectorPage's own docblock draws in rule 1 ("items === [] says nothing about
 * the stream"), and it is exactly how TrustpilotConnector counts.
 *
 * ## Decision: a boost is not feedback
 *
 * A status with `reblog !== null` is someone re-sharing another account's post.
 * The words are not theirs, the nested original will arrive on its own if it
 * carries the hashtag, and ingesting the wrapper would spend a unit of analysis
 * quota on a row whose body is empty. It is skipped — the same judgement that
 * skips a Trustpilot review with no text. (The recorded capture contained no
 * boosts at all: Mastodon's tag timeline excludes them. This check is therefore
 * defensive, and it costs nothing to keep for the servers that do not.)
 *
 * ## Decision: `author` is the display name, never `acct`
 *
 * For a remote account `acct` is `user@domain`, and `IngestionRunner::maskPii`
 * rewrites anything address-shaped to `[email]` before it is stored. Passing
 * `acct` through would therefore not merely be untidy — it would throw the name
 * away and put the literal string `[email]` in the inbox where a person's name
 * belongs. `display_name` is used, falling back to `username` when the account
 * left it blank. The masking of `acct` *inside* `raw_payload` is correct and
 * stays; it is a direct identifier the product has no use for (spec §8).
 */
final readonly class MastodonConnector implements PlatformConnector
{
    /**
     * The hashtag is substituted into the URL **path**, so it is whitelisted
     * rather than escaped — the same reasoning as Trustpilot's 24-hex id and
     * Google Play's package name. A value carrying `/`, `?` or `..` would point
     * the request at an endpoint of the writer's choosing.
     *
     * Letters, digits and underscore only, which is what Mastodon itself
     * accepts in a tag. Unicode-aware, because a Turkish hashtag is a hashtag.
     */
    private const HASHTAG = '/^[\p{L}\p{N}_]{1,100}$/u';

    /** Documented ceiling for `limit` on a timeline; a larger value is clamped, not sent. */
    private const MAX_LIMIT = 40;

    public function __construct(
        private string $instanceUrl,
        private string $hashtag,
        private ConnectorLimits $limits,
        private int $timeout,
        private int $limit,
    ) {
        // Misconfigured rather than InvalidCredentials for both: there is no
        // credential on this channel at all, so a refusal here can only be
        // about the settings, and the message the user sees has to say so.
        if (! OutboundHostPolicy::isHttpsUrl($instanceUrl)) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }

        if (preg_match(self::HASHTAG, $hashtag) !== 1) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }
    }

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $token = SyncCursor::decode($cursor)->token;
        $token = $token === '' ? null : $token;

        $statuses = $this->statusesFrom($this->request($token));

        $pageWatermark = null;
        $items = [];

        foreach ($statuses as $status) {
            $publishedAt = $this->publishedAt($status);
            $pageWatermark = (new SyncCursor(watermark: $pageWatermark))->advancedTo($publishedAt)->watermark;

            $item = $this->toItem($status, $publishedAt);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        // A cold start reads the newest page and stops. Afterwards the run
        // walks forward from the token while the pages come back full; a short
        // page is how a limit-based API says there is nothing behind it. The
        // runner's maxPagesPerRun is deliberately not consulted: a connector
        // that answered hasMore=false on reaching the runner's ceiling would be
        // telling the runner the run *completed*, letting it persist a position
        // it never reached (docs/LESSONS.md, capped-run entry).
        $hasMore = $token !== null && count($statuses) === $this->limit();

        // The newest id on the page, which on a newest-first page is the first
        // element's. Never null-ed out on an empty page: the stored token is
        // the position, and dropping it would restart the next run from the
        // newest page.
        $next = $this->newestId($statuses) ?? $token;

        return new ConnectorPage(
            items: $items,
            hasMore: $hasMore,
            nextCursor: (new SyncCursor)->withToken($next)->encode(),
            watermark: $pageWatermark,
        );
    }

    public function limits(): ConnectorLimits
    {
        return $this->limits;
    }

    public function healthCheck(): ConnectorHealth
    {
        try {
            $this->statusesFrom($this->request(null));
        } catch (ConnectorException $e) {
            return ConnectorHealth::failing($e->failure());
        }

        return ConnectorHealth::ok();
    }

    /**
     * One call to the public hashtag timeline: `min_id` when we have a
     * position, otherwise just the page size for the cold start.
     */
    private function request(?string $token): Response
    {
        $url = rtrim($this->instanceUrl, '/')
            .'/api/v1/timelines/tag/'.rawurlencode($this->hashtag);

        // The instance URL is tenant-supplied and this is the first request that
        // dereferences it, so the host is checked here before the token-free GET
        // and again at every redirect hop through the client's allow_redirects.
        OutboundHostPolicy::assertAllowed($url);

        $query = ['limit' => $this->limit()];

        if ($token !== null) {
            $query['min_id'] = $token;
        }

        try {
            $response = $this->client()->get($url, $query);
        } catch (ConnectionException $e) {
            throw ConnectorException::of(ConnectorFailure::Unreachable, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw ConnectorException::of(match (true) {
            // The instance has public preview switched off, so the timeline
            // needs a token this connector does not implement. Terminal: the
            // user has to point the integration at another instance.
            $response->status() === 401 => ConnectorFailure::InvalidCredentials,
            // No such endpoint on this host — the instance URL is not a
            // Mastodon-compatible server, or the path was refused.
            $response->status() === 404 => ConnectorFailure::Misconfigured,
            // The request shape was refused; on this endpoint that is the
            // settings, not the platform, and it repeats identically however
            // often it is retried.
            $response->status() === 422 => ConnectorFailure::Misconfigured,
            // A suspended or defederated instance answers 403. That is a
            // standing decision by the server, not a blip — Unreachable is
            // retryable by design and would burn five attempts on a refusal
            // that will read identically on the sixth.
            $response->status() === 403 => ConnectorFailure::Misconfigured,
            $response->status() === 429 => ConnectorFailure::RateLimited,
            default => ConnectorFailure::Unreachable,
        });
    }

    private function client(): PendingRequest
    {
        // No Authorization header, by design: the public timeline takes none,
        // and this connector holds no credential to send.
        //
        // allow_redirects stays on — a Mastodon-compatible host may redirect the
        // timeline path — but every hop's target is re-validated by the policy
        // before it is followed, and only https is followed at all.
        return Http::acceptJson()
            ->timeout($this->timeout)
            ->withOptions(['allow_redirects' => OutboundHostPolicy::redirectOptions()]);
    }

    private function limit(): int
    {
        return max(1, min(self::MAX_LIMIT, $this->limit));
    }

    /**
     * The timeline answers a bare JSON **list**. An object is not an envelope
     * this connector knows, and guessing at one would be worse than refusing:
     * every error this endpoint returns is an object, so accepting one would
     * turn a refusal into a silent "no new feedback".
     *
     * The body is checked as well as the decoded value because `{}` and `[]`
     * are indistinguishable once `json_decode` has run with associative
     * arrays on — both become `[]`, and only one of them is a page.
     *
     * @return list<array<string, mixed>>
     */
    private function statusesFrom(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded) || ! array_is_list($decoded)
            || ! str_starts_with(ltrim($response->body()), '[')) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return array_values(array_filter($decoded, is_array(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $statuses
     */
    private function newestId(array $statuses): ?string
    {
        foreach ($statuses as $status) {
            $id = $this->trimmed($status['id'] ?? null);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function toItem(array $status, ?string $publishedAt): ?ConnectorItem
    {
        $externalId = $this->trimmed($status['id'] ?? null);

        if ($externalId === null) {
            return null;
        }

        // A boost carries someone else's words; see the class docblock.
        if (($status['reblog'] ?? null) !== null) {
            return null;
        }

        $body = $this->body($status);

        if ($body === null) {
            // A media-only post strips to nothing. Ingesting it would put a
            // blank row in the inbox and spend a unit of analysis quota on it.
            return null;
        }

        return new ConnectorItem(
            externalId: $externalId,
            author: $this->author($status),
            body: $body,
            // `url` is the human-facing permalink; a remote status Mastodon has
            // no local permalink for carries only `uri`.
            sourceUrl: $this->trimmed($status['url'] ?? null) ?? $this->trimmed($status['uri'] ?? null),
            publishedAt: $publishedAt,
            // A status has no rating of any kind. Favourites and boosts are
            // popularity, not sentiment, and projecting them onto 1-5 would
            // invent a number the platform never expressed.
            rating: null,
            rawPayload: $status,
        );
    }

    /**
     * `display_name`, falling back to `username`, and **never `acct`** — see the
     * class docblock for why passing `acct` would put `[email]` in the inbox
     * where a name belongs.
     *
     * @param  array<string, mixed>  $status
     */
    private function author(array $status): ?string
    {
        return $this->trimmed(data_get($status, ['account', 'display_name']))
            ?? $this->trimmed(data_get($status, ['account', 'username']));
    }

    /**
     * `content` is server-sanitised HTML, and the analyzer reads plain text.
     *
     * The order matters and is the reason this is not a bare `strip_tags`:
     * paragraph and line breaks carry structure a reviewer wrote deliberately,
     * so they become newlines *before* the tags are removed. Entities are
     * decoded last, after the markup is gone, so a `&lt;` in the original text
     * cannot re-enter as a tag.
     *
     * Null when nothing is left — a media-only post really does answer an empty
     * `content`.
     *
     * @param  array<string, mixed>  $status
     */
    private function body(array $status): ?string
    {
        $content = $status['content'] ?? null;

        if (! is_string($content)) {
            return null;
        }

        $text = preg_replace('#<br\s*/?>#i', "\n", $content) ?? $content;
        $text = preg_replace('#</p\s*>#i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse the run of blank lines the </p> substitution leaves at the
        // end, and normalise the non-breaking spaces Mastodon clients emit.
        $text = str_replace("\u{00a0}", ' ', $text);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return $this->trimmed($text);
    }

    /**
     * `created_at`, offset preserved. The runner stores it with
     * `toIso8601String()`; `toDateTimeString()` drops the offset and has
     * already put a row seven hours off once (docs/LESSONS.md).
     *
     * @param  array<string, mixed>  $status
     */
    private function publishedAt(array $status): ?string
    {
        return $this->trimmed($status['created_at'] ?? null);
    }

    /**
     * A non-empty string, or null.
     */
    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
