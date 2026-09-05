<?php

namespace App\Support\Connectors;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Play customer reviews, through the Android Publisher API.
 *
 * `GET /androidpublisher/v3/applications/{packageName}/reviews` answers
 * `{"reviews": [...], "tokenPagination": {"nextPageToken": "..."}}`, newest
 * first. The absence of `tokenPagination` is the end of the stream, and it is
 * the only thing that ends it — an empty `reviews` array does not, and neither
 * does a missing `reviews` key: the protobuf-to-JSON mapping omits empty
 * repeated fields, so an application with nothing in the seven-day window
 * answers `{}` and that is a healthy empty page. See reviews() below.
 *
 * ## Why this is the App Store shape and not the Zendesk shape
 *
 * `reviews.list` only serves roughly the last **seven days**. There is no way
 * to ask for anything older, so there is no history to walk and no benefit in
 * an opaque position that survives between runs. The connector re-lists from
 * the top on every run and stops as soon as it reaches the stored watermark;
 * everything above it that was already ingested is absorbed by
 * `UNIQUE (integration_id, external_id)` (invariant I2). That is not the full
 * re-scan spec 6.1 forbids — the window is the entire feed the platform offers,
 * and the run still stops at the stored position rather than at the end of it.
 *
 * So the two cursor fields carry different lifetimes:
 *
 *  - `SyncCursor::$token` holds `nextPageToken`, and only within one run.
 *  - `SyncCursor::$watermark` holds the position across runs, and is frozen for
 *    the whole run: `pending` accumulates page by page and IngestionRunner
 *    promotes it, once, when the run actually reached the end. A watermark
 *    advanced mid-run makes every item on page 2 of a newest-first feed compare
 *    as already seen, and the run silently ingests only its first page
 *    (docs/LESSONS.md, 2026-09-02).
 *
 * The runner page cap is deliberately **not** part of `hasMore`. This platform
 * has no depth limit of its own — unlike the App Store feed, where reporting
 * hasMore=false at page 10 is the honest answer — so a connector that reported
 * hasMore=false on reaching the cap would tell the runner a run was complete
 * that had not reached the end, and the runner would promote a watermark that
 * buries every unread page beneath it.
 *
 * ## A page token that did not survive to the next run
 *
 * A run cut short by the runner cap stores a cursor that still carries a
 * `nextPageToken`, and the next run resumes from it — which is what makes
 * progress possible when a backlog is deeper than one run. Page tokens are not
 * documented to survive that gap, though, and a token the platform refuses
 * would otherwise wedge the integration permanently: the run fails, the cursor
 * is never rewritten, and every later run replays the same refusal.
 *
 * So a request that carried a token and was refused with **400** is retried
 * once without it, from the top of the feed. That costs one extra request in a
 * rare case, cannot loop (the retry carries no token), and the watermark plus
 * I2 make the replayed pages cheap and duplicate-free.
 *
 * ## Where the shape came from
 *
 * There is no Google Play developer account behind this. The endpoint, the
 * response envelope, the review field set, the service-account JWT flow and the
 * error statuses come from published documentation, and the fixtures under
 * tests/Fixtures/platforms/googleplay/ are synthesised from it.
 * contracts/fixtures/platforms/googleplay/README.md records, field by field,
 * what is documented and what is inferred.
 *
 * ## Invariant I5
 *
 * This connector never touches the service-account credentials. They live in
 * GooglePlayAccessToken, which hands back only a bearer token, and that token
 * goes into the Authorization header — never the URL, never the query string.
 * Nothing thrown here is built from a response, and the class logs nothing.
 */
final readonly class GooglePlayConnector implements PlatformConnector
{
    /**
     * Java package syntax, and nothing else.
     *
     * The package name is substituted into the request path, so it is
     * whitelisted rather than escaped — the same reasoning as
     * ConnectorFactory::subdomain(). A value carrying `/`, `?` or `..` could
     * point the authenticated request at a different resource entirely. The
     * regex lives here because the factory is not this workstream's to edit.
     */
    private const PACKAGE_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/';

    /**
     * Documented ceiling on `maxResults`.
     */
    private const MAX_PAGE_SIZE = 100;

    /**
     * Longest `nextPageToken` this connector will carry.
     *
     * `integrations.sync_cursor` is varchar(255) and the encoded SyncCursor has
     * to fit inside it. The worst case envelope here is a page number, an
     * ISO-8601 watermark, an ISO-8601 pending value and the token, which is
     * roughly 100 characters around the token itself. Google documents the
     * token as opaque and says nothing about its length, so this is the largest
     * value that can be stored rather than a measured bound; anything longer
     * would overflow the column and fail the run with a database error that
     * says nothing useful about the cause.
     */
    private const MAX_TOKEN_LENGTH = 150;

    /**
     * @throws ConnectorException when the package name is not a package name
     */
    public function __construct(
        private string $packageName,
        private GooglePlayAccessToken $token,
        private string $baseUrl,
        private ConnectorLimits $limits,
        private int $timeout,
        private int $maxResults,
    ) {
        if (preg_match(self::PACKAGE_NAME_PATTERN, $packageName) !== 1) {
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }
    }

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $state = SyncCursor::decode($cursor);

        $body = $this->decode($this->request($state->token));
        $reviews = $this->reviews($body);

        $pageWatermark = null;
        $caughtUp = false;
        $items = [];

        foreach ($reviews as $review) {
            $comment = $this->userComment($review);
            $publishedAt = $this->publishedAt($comment);
            $pageWatermark = (new SyncCursor(watermark: $pageWatermark))->advancedTo($publishedAt)->watermark;

            if ($state->alreadySeen($publishedAt)) {
                // Newest first, so reaching the stored watermark means this run
                // has caught up with the previous one. That is the incremental
                // stop condition spec 6.1 asks for.
                $caughtUp = true;

                continue;
            }

            $item = $this->toItem($review, $comment, $publishedAt);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        $nextPageToken = $this->nextPageToken($body);

        // Only two things end the stream: catching up with the stored
        // watermark, and the absence of tokenPagination. An empty page ends
        // nothing — the runner counts the streak and decides separately.
        $hasMore = ! $caughtUp && $nextPageToken !== null;

        $reached = $state->pendingAdvancedTo($pageWatermark);

        // The token is dropped as soon as the run is over, so the next run
        // starts at the top of the feed with nothing stale to replay. Promotion
        // of `pending` onto `watermark` is IngestionRunner business: only it
        // knows whether an earlier page of this run came back empty, and
        // promoting after a skipped page buries that page below the watermark
        // permanently.
        $nextCursor = $hasMore
            ? $reached->withToken($nextPageToken)
            : $reached->withToken(null);

        return new ConnectorPage(
            items: $items,
            hasMore: $hasMore,
            nextCursor: $nextCursor->encode(),
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
            // Deliberately the request and not the body: this answers "can this
            // integration reach its platform right now", and the expensive part
            // of that — minting an access token from the stored service account
            // — is exactly what this exercises.
            $this->request(null);
        } catch (ConnectorException $e) {
            return ConnectorHealth::failing($e->failure());
        }

        return ConnectorHealth::ok();
    }

    /**
     * One page of reviews, with the stale-token recovery described above.
     *
     * @throws ConnectorException
     */
    private function request(?string $token): Response
    {
        $response = $this->send($token);

        if ($response->successful()) {
            return $response;
        }

        if ($response->status() === 400 && $token !== null && $token !== '') {
            // The only argument this request carries that can go stale between
            // runs. Retried once from the top of the feed rather than left to
            // fail identically on every future run.
            $retried = $this->send(null);

            if ($retried->successful()) {
                return $retried;
            }

            throw ConnectorException::of($this->failureFor($retried->status()));
        }

        throw ConnectorException::of($this->failureFor($response->status()));
    }

    /**
     * @throws ConnectorException
     */
    private function send(?string $token): Response
    {
        $url = rtrim($this->baseUrl, '/')
            .'/androidpublisher/v3/applications/'.$this->packageName.'/reviews';

        $query = ['maxResults' => $this->pageSize()];

        if ($token !== null && $token !== '') {
            $query['token'] = $token;
        }

        try {
            // The bearer token rides in the Authorization header, never in the
            // query string: a credential in a URL is written into every proxy
            // and access log between here and Google.
            return Http::withToken($this->token->get())
                ->acceptJson()
                ->timeout($this->timeout)
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw ConnectorException::of(ConnectorFailure::Unreachable, $e);
        }
    }

    private function failureFor(int $status): ConnectorFailure
    {
        return match (true) {
            // Either the access token was refused or the service account is not
            // attached to this Play Console account. Both are terminal and both
            // are the same thing from the user side: the credentials do not
            // work for this application.
            in_array($status, [401, 403], true) => ConnectorFailure::InvalidCredentials,
            // No such package, or an account that cannot see it.
            $status === 404 => ConnectorFailure::Misconfigured,
            // A 400 that survived the stale-token retry above means the request
            // shape or the package name is not acceptable, and that repeats
            // identically however often it is retried. Terminal, therefore, and
            // Misconfigured rather than Unreachable: Unreachable is transient,
            // so FetchFeedbackJob would spend five attempts on it before the
            // user saw anything, and the message would blame the platform for a
            // problem that is in the integration settings. This is a deliberate
            // addition to the W8 contract error table, which lists no 400.
            $status === 400 => ConnectorFailure::Misconfigured,
            $status === 429 => ConnectorFailure::RateLimited,
            default => ConnectorFailure::Unreachable,
        };
    }

    private function pageSize(): int
    {
        return min(max(1, $this->maxResults), self::MAX_PAGE_SIZE);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConnectorException
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        // A non-empty top-level JSON array is not an envelope. `{}` and `[]`
        // are indistinguishable once decoded — both become `[]` — and the first
        // of the two is a real answer this endpoint gives, so the pair is
        // accepted and read as an envelope with no keys.
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return $decoded;
    }

    /**
     * The reviews on this page, and `[]` when there are none.
     *
     * **An absent `reviews` key is an empty page, not a broken response.** The
     * protobuf-to-JSON mapping omits empty repeated fields, so an application
     * with nothing in the seven-day window answers `{}` — and refusing that
     * would report a perfectly healthy integration as permanently broken, which
     * is a worse failure than the one the check was guarding against. An empty
     * page is already harmless here: `hasMore` is decided by `tokenPagination`
     * alone, and IngestionRunner counts an empty streak only within one run.
     *
     * A `reviews` that is present but is not a list is a different thing
     * entirely, and is still a response this connector cannot parse.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     *
     * @throws ConnectorException
     */
    private function reviews(array $body): array
    {
        if (! array_key_exists('reviews', $body)) {
            return [];
        }

        $reviews = $body['reviews'];

        if (! is_array($reviews) || ! array_is_list($reviews)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return array_values(array_filter($reviews, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ConnectorException
     */
    private function nextPageToken(array $body): ?string
    {
        $token = data_get($body, ['tokenPagination', 'nextPageToken']);

        if (! is_string($token) || $token === '') {
            return null;
        }

        if (mb_strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return $token;
    }

    /**
     * The customer half of a review.
     *
     * A review carries a `comments` list holding one `userComment` and,
     * whenever the developer has replied, a `developerComment` as well. Only
     * the first is feedback: ingesting a developer reply would analyse the
     * company answering itself, and spend a unit of quota doing it.
     *
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>|null
     */
    private function userComment(array $review): ?array
    {
        $comments = $review['comments'] ?? null;

        if (! is_array($comments)) {
            return null;
        }

        foreach ($comments as $comment) {
            $userComment = is_array($comment) ? ($comment['userComment'] ?? null) : null;

            if (is_array($userComment)) {
                return $userComment;
            }
        }

        return null;
    }

    /**
     * `lastModified.seconds`, as an ISO-8601 string with its offset.
     *
     * The field is a protobuf Timestamp, so JSON encodes its `seconds` as a
     * string rather than a number; both forms are accepted. The offset matters:
     * `published_at` is `timestamptz`, and a value that loses its offset lands
     * as the right wall clock in the wrong zone (docs/LESSONS.md, 2026-09-02).
     *
     * @param  array<string, mixed>|null  $comment
     */
    private function publishedAt(?array $comment): ?string
    {
        $seconds = data_get($comment ?? [], ['lastModified', 'seconds']);

        if (! is_numeric($seconds)) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $seconds)->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $review
     * @param  array<string, mixed>|null  $comment
     */
    private function toItem(array $review, ?array $comment, ?string $publishedAt): ?ConnectorItem
    {
        $externalId = $review['reviewId'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        // A review with no user comment is a developer reply on its own, or a
        // star rating left without text. Neither carries anything to analyse,
        // and storing one would put an empty row in the inbox.
        $text = is_array($comment) ? ($comment['text'] ?? null) : null;

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $rating = $comment['starRating'] ?? null;
        $author = $review['authorName'] ?? null;

        return new ConnectorItem(
            externalId: $externalId,
            // Reviews can be left anonymously, in which case the field is
            // absent rather than empty.
            author: is_string($author) && trim($author) !== '' ? $author : null,
            body: $text,
            // Google Play publishes no per-review permalink; the store listing
            // is the only public page a reader of the inbox can be sent to.
            sourceUrl: 'https://play.google.com/store/apps/details?id='.$this->packageName,
            publishedAt: $publishedAt,
            // Documented as an integer 1-5. Anything outside that is not a
            // rating this product can display, so it is dropped rather than
            // clamped into a value the reviewer did not give.
            rating: is_numeric($rating) && (int) $rating >= 1 && (int) $rating <= 5 ? (int) $rating : null,
            rawPayload: $review,
        );
    }
}
