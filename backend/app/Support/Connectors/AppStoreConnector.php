<?php

namespace App\Support\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The public App Store customer-review RSS feed.
 *
 * Measured against the live feed on 2026-09-02; the three behaviours below are
 * recorded facts, not defensive guesses, and the fixtures under
 * tests/Fixtures/platforms/appstore/ are the captured responses.
 *
 *  1. **No credentials.** The feed is public, so this connector never reads
 *     `integrations.credentials` at all — the cheapest possible answer to
 *     invariant I5.
 *  2. **Page depth is capped at 10.** `page=11` answers HTTP 400 with a gzip'd
 *     plain-text body. The status code is the signal; the body is not corrupt
 *     JSON and must not be parsed. Because a full history walk is therefore
 *     impossible, the watermark in SyncCursor is mandatory rather than an
 *     optimisation.
 *  3. **Pages come back empty intermittently.** `page=1` returned 0 entries on
 *     one measurement and 50 on the five that followed. So an empty page must
 *     never end the run: `hasMore` is decided by the watermark and the depth
 *     cap, never by `$items === []`.
 */
final readonly class AppStoreConnector implements PlatformConnector
{
    public function __construct(
        private string $appId,
        private string $country,
        private string $baseUrl,
        private ConnectorLimits $limits,
        private int $timeout,
    ) {}

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $cursorState = SyncCursor::decode($cursor);
        $page = min($cursorState->page, $this->limits->maxPagesPerRun);

        $entries = $this->entriesFrom($this->request($page));

        $pageWatermark = null;
        $caughtUp = false;
        $items = [];

        foreach ($entries as $entry) {
            $publishedAt = $this->label($entry, ['updated']);
            $pageWatermark = (new SyncCursor(watermark: $pageWatermark))->advancedTo($publishedAt)->watermark;

            if ($cursorState->alreadySeen($publishedAt)) {
                // Sorted newest-first, so reaching the watermark means this run
                // has caught up with the previous one. That is the incremental
                // stop condition spec 6.1 asks for.
                $caughtUp = true;

                continue;
            }

            $item = $this->toItem($entry, $publishedAt);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        $reached = $cursorState->pendingAdvancedTo($pageWatermark);
        $hasMore = ! $caughtUp && $page < $this->limits->maxPagesPerRun;

        // Mid-run only `pending` moves; the watermark alreadySeen() compares
        // against stays frozen until the run ends. Advancing it page by page on
        // a newest-first feed makes page 2 look entirely already-ingested, and
        // the run silently stops after its first page.
        //
        // On the last page the cursor promotes pending and rewinds to page 1:
        // the next run starts at the top of the feed and stops at the watermark
        // this one just established.
        // Promotion is the runner's call, not this method's: only the runner
        // knows whether an earlier page of the same run came back empty, and
        // promoting after a skipped page buries its items below the watermark
        // forever. Here the cursor only advances `pending` and the page number.
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
            $this->request(1);
        } catch (ConnectorException $e) {
            return ConnectorHealth::failing($e->failure());
        }

        return ConnectorHealth::ok();
    }

    private function request(int $page): Response
    {
        $url = sprintf(
            '%s/%s/rss/customerreviews/page=%d/id=%s/sortBy=mostRecent/json',
            rtrim($this->baseUrl, '/'),
            rawurlencode(strtolower($this->country)),
            $page,
            rawurlencode($this->appId),
        );

        try {
            $response = Http::acceptJson()->timeout($this->timeout)->get($url);
        } catch (ConnectionException $e) {
            throw ConnectorException::of(ConnectorFailure::Unreachable, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw ConnectorException::of(match (true) {
            // Documented: "CustomerReviews RSS page depth is limited to 10".
            $response->status() === 400 => ConnectorFailure::DepthLimitExceeded,
            in_array($response->status(), [401, 403], true) => ConnectorFailure::InvalidCredentials,
            $response->status() === 429 => ConnectorFailure::RateLimited,
            default => ConnectorFailure::Unreachable,
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entriesFrom(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded) || ! isset($decoded['feed']) || ! is_array($decoded['feed'])) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        $entries = $decoded['feed']['entry'] ?? [];

        if (! is_array($entries)) {
            throw ConnectorException::of(ConnectorFailure::MalformedResponse);
        }

        // A feed holding exactly one review serialises `entry` as an object
        // rather than a list. Normalising here keeps the caller unaware.
        if ($entries !== [] && ! array_is_list($entries)) {
            $entries = [$entries];
        }

        return array_values(array_filter($entries, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function toItem(array $entry, ?string $publishedAt): ?ConnectorItem
    {
        $externalId = $this->label($entry, ['id']);

        if ($externalId === null) {
            return null;
        }

        $rating = $this->label($entry, ['im:rating']);

        return new ConnectorItem(
            externalId: $externalId,
            author: $this->label($entry, ['author', 'name']),
            body: $this->label($entry, ['content']) ?? '',
            sourceUrl: $this->attribute($entry, ['link'], 'href'),
            publishedAt: $publishedAt,
            rating: is_numeric($rating) ? (int) $rating : null,
            rawPayload: $entry,
        );
    }

    /**
     * The feed nests every scalar one level down as `{"label": "..."}`.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $path
     */
    private function label(array $entry, array $path): ?string
    {
        $value = data_get($entry, [...$path, 'label']);

        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $path
     */
    private function attribute(array $entry, array $path, string $name): ?string
    {
        $value = data_get($entry, [...$path, 'attributes', $name]);

        return is_string($value) ? $value : null;
    }
}
