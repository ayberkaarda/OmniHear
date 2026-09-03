<?php

namespace App\Support\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Trustpilot business-unit reviews, through the public Business Units API.
 *
 * Newest-first and page-based, which makes this AppStoreConnector's sibling
 * rather than a second dialect: `SyncCursor::$page` is the intra-run pointer,
 * `SyncCursor::$watermark` is the inter-run one, and a run stops as soon as it
 * reaches an item at or below the stored watermark (spec 6.1 — a full re-scan
 * on every sync is forbidden).
 *
 * ## Where the shape came from
 *
 * There is no Trustpilot business account behind this project. The request
 * shape, the `apikey` header, the `reviews` envelope, the review field set and
 * the error statuses are taken from Trustpilot's published API documentation,
 * and the fixtures under tests/Fixtures/platforms/trustpilot/ are synthesised
 * from it — the same position ZendeskConnector is in.
 * contracts/fixtures/platforms/trustpilot/README.md records, field by field,
 * what is documented and what is an inference. Read it before trusting a detail
 * here against a live account.
 *
 * ## Invariant I5 — the API key
 *
 * The same three structural rules ZendeskConnector carries:
 *
 *  1. The key is a constructor argument in a private property, handed to
 *     `withHeaders()` and nowhere else. **It never travels in the query
 *     string.** Trustpilot's documentation shows the key as an `apikey` header;
 *     a key in a query string is written into every access log between here and
 *     Trustpilot, which is an I5 violation even though the value never reaches
 *     a log of ours.
 *  2. Nothing thrown here is built from a response. Every failure is one of the
 *     fixed ConnectorFailure sentences, so `integrations.sync_error` cannot
 *     carry credential material even if an upstream error body echoed the key
 *     back at us.
 *  3. This class logs nothing at all.
 *
 * ## Decision: `title` and `text` become one body
 *
 * A Trustpilot review carries a headline (`title`) and a free-text body
 * (`text`) as separate fields, and both carry meaning — the headline is often
 * the sentiment ("Kargo çok yavaş") and the body the detail. The analyzer sees
 * `feedbacks.body` and nothing else, so dropping either would throw away
 * signal. They are therefore joined as `title . "\n\n" . text` when both are
 * present, whichever exists is used alone when only one is, and a review with
 * neither is **skipped** rather than ingested as an empty row: an empty row
 * would sit in the inbox and spend a unit of analysis quota on nothing.
 *
 * ## Decision: the run's stop conditions
 *
 * `hasMore` is false when — and only when — the page reached the stored
 * watermark, or the page was short (`count(reviews) < perPage`, which is how a
 * page-based API says "there is nothing behind this"). The runner's
 * `maxPagesPerRun` is deliberately **not** one of them: unlike the App Store
 * feed, Trustpilot publishes no page-depth ceiling, so a connector that
 * reported `hasMore=false` on reaching the runner's cap would be telling the
 * runner the run completed and letting it promote a watermark it never reached
 * — burying every unfetched page below it forever
 * (docs/LESSONS.md, empty-middle-page and capped-run entries). The cap is the
 * runner's runaway-loop ceiling and stays the runner's to apply; a capped run
 * resumes from the page number in the cursor.
 *
 * Mid-run only `pending` moves. The watermark `alreadySeen()` compares against
 * stays frozen until the runner promotes it, because on a newest-first feed a
 * watermark advanced after page 1 makes every item on page 2 compare as
 * already-seen and the run silently ingests only its first page.
 */
final readonly class TrustpilotConnector implements PlatformConnector
{
    /**
     * Trustpilot business unit ids are 24 hex characters.
     *
     * The value is substituted into the URL **path**, so it is whitelisted
     * rather than escaped — the same reasoning as ConnectorFactory::subdomain().
     * A value carrying `/`, `?` or `..` would point the authenticated request,
     * and the apikey header with it, at an endpoint of the writer's choosing.
     * The regex lives here rather than in the factory because the factory is
     * shared between platforms and this rule is this platform's.
     */
    private const BUSINESS_UNIT_ID = '/^[a-f0-9]{24}$/i';

    /**
     * Not optional, and not a preference.
     *
     * The whole cursor model rests on the feed being newest-first: without an
     * explicit ordering the API's default order is not guaranteed, and a
     * watermark on an unordered feed stops the run at an arbitrary point while
     * claiming it caught up. Asserted by a test that inspects the outgoing
     * request, not only by this comment.
     */
    private const ORDER_BY = 'createdat.desc';

    /** Documented ceiling for `perPage`; a larger value is clamped, not sent. */
    private const MAX_PER_PAGE = 100;

    /**
     * The human-facing review permalink, not an API-documented field.
     *
     * The review's own `links` entries point back at the API, which is useless
     * to someone opening the item from the inbox. Inferred — see the fixtures
     * README.
     */
    private const REVIEW_URL_PREFIX = 'https://www.trustpilot.com/reviews/';

    public function __construct(
        private string $businessUnitId,
        private string $apiKey,
        private string $baseUrl,
        private ConnectorLimits $limits,
        private int $timeout,
        private int $perPage,
    ) {
        if (preg_match(self::BUSINESS_UNIT_ID, $businessUnitId) !== 1) {
            // Misconfigured, not InvalidCredentials: the settings are wrong,
            // not the key, and the message the user sees has to say so.
            throw ConnectorException::of(ConnectorFailure::Misconfigured);
        }
    }

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $state = SyncCursor::decode($cursor);
        $page = $state->page;

        $reviews = $this->reviewsFrom($this->request($page));

        $pageWatermark = null;
        $caughtUp = false;
        $items = [];

        foreach ($reviews as $review) {
            $publishedAt = $this->publishedAt($review);
            $pageWatermark = (new SyncCursor(watermark: $pageWatermark))->advancedTo($publishedAt)->watermark;

            if ($state->alreadySeen($publishedAt)) {
                // Sorted newest-first, so reaching the watermark means this run
                // has caught up with the previous one. That is the incremental
                // stop condition spec 6.1 asks for.
                $caughtUp = true;

                continue;
            }

            $item = $this->toItem($review, $publishedAt);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        // A page holding fewer reviews than were asked for is the end of the
        // feed: on a page-based API there is nothing behind it. The runner's
        // page cap is not consulted here — see the class docblock.
        $shortPage = count($reviews) < $this->perPage();
        $hasMore = ! $caughtUp && ! $shortPage;

        $reached = $state->pendingAdvancedTo($pageWatermark);

        // On the last page the cursor rewinds to page 1 so the next run starts
        // at the top of the feed and stops at the watermark this one
        // established. Promotion of `pending` onto `watermark` is the runner's
        // call, not this method's: only the runner knows whether an earlier
        // page of the same run came back empty or the cap cut the run short.
        $nextCursor = $reached->withPage($hasMore ? $page + 1 : 1);

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
            $this->reviewsFrom($this->request(1));
        } catch (ConnectorException $e) {
            return ConnectorHealth::failing($e->failure());
        }

        return ConnectorHealth::ok();
    }

    private function request(int $page): Response
    {
        $url = rtrim($this->baseUrl, '/')
            .'/v1/business-units/'.$this->businessUnitId.'/reviews';

        try {
            $response = $this->client()->get($url, [
                'page' => max(1, $page),
                'perPage' => $this->perPage(),
                'orderBy' => self::ORDER_BY,
            ]);
        } catch (ConnectionException $e) {
            throw ConnectorException::of(ConnectorFailure::Unreachable, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw ConnectorException::of(match (true) {
            // 401 is a key the API does not recognise; 403 is a key that exists
            // but may not read this business unit. Both are the user's
            // credentials being refused, and both are terminal — retrying
            // changes nothing.
            in_array($response->status(), [401, 403], true) => ConnectorFailure::InvalidCredentials,
            // The business unit id is well-formed but no such unit is visible.
            $response->status() === 404 => ConnectorFailure::Misconfigured,
            $response->status() === 429 => ConnectorFailure::RateLimited,
            default => ConnectorFailure::Unreachable,
        });
    }

    /**
     * The key rides in the `apikey` header and nowhere else — never in the
     * query string, where every proxy between here and Trustpilot would log it.
     */
    private function client(): PendingRequest
    {
        return Http::withHeaders(['apikey' => $this->apiKey])
            ->acceptJson()
            ->timeout($this->timeout);
    }

    private function perPage(): int
    {
        return max(1, min(self::MAX_PER_PAGE, $this->perPage));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewsFrom(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded) || ! array_key_exists('reviews', $decoded)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        $reviews = $decoded['reviews'];

        if (! is_array($reviews)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        return array_values(array_filter($reviews, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $review
     */
    private function toItem(array $review, ?string $publishedAt): ?ConnectorItem
    {
        $externalId = $this->trimmed($review['id'] ?? null);

        if ($externalId === null) {
            return null;
        }

        $body = $this->body($review);

        if ($body === null) {
            // Neither a headline nor a body: nothing for the analyzer to read.
            // Ingesting it would put an empty row in the inbox and spend a unit
            // of analysis quota on it.
            return null;
        }

        return new ConnectorItem(
            externalId: $externalId,
            author: $this->trimmed(data_get($review, ['consumer', 'displayName'])),
            body: $body,
            sourceUrl: self::REVIEW_URL_PREFIX.rawurlencode($externalId),
            publishedAt: $publishedAt,
            rating: $this->rating($review),
            rawPayload: $review,
        );
    }

    /**
     * `title` and `text` joined, per the decision recorded in the class
     * docblock. Null when the review carries neither.
     *
     * @param  array<string, mixed>  $review
     */
    private function body(array $review): ?string
    {
        $title = $this->trimmed($review['title'] ?? null);
        $text = $this->trimmed($review['text'] ?? null);

        if ($title !== null && $text !== null) {
            return $title."\n\n".$text;
        }

        return $title ?? $text;
    }

    /**
     * `createdAt`, not `updatedAt`: published_at is when the customer said
     * this, and an edit or a company reply must not move a review forward in
     * the inbox or in the trend charts.
     *
     * @param  array<string, mixed>  $review
     */
    private function publishedAt(array $review): ?string
    {
        return $this->trimmed($review['createdAt'] ?? null);
    }

    /**
     * Trustpilot's star rating is already the 1-5 scale the rest of the product
     * speaks, so it needs no projection — only a range check, because a value
     * outside it is not a rating this product can render.
     *
     * @param  array<string, mixed>  $review
     */
    private function rating(array $review): ?int
    {
        $stars = $review['stars'] ?? null;

        if (! is_numeric($stars)) {
            return null;
        }

        $stars = (int) $stars;

        return $stars >= 1 && $stars <= 5 ? $stars : null;
    }

    /**
     * A non-empty string, or null. Trustpilot serialises an absent `title` or
     * `text` as `null` on some reviews and as `""` on others, and the two have
     * to mean the same thing here.
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
