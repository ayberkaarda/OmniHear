<?php

use App\Events\FeedbackIngested;
use App\Models\Feedback;
use App\Support\Connectors\ConnectorHealth;
use App\Support\Connectors\ConnectorItem;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\ConnectorPage;
use App\Support\Connectors\PlatformConnector;
use App\Support\Connectors\SyncCursor;
use Illuminate\Support\Facades\Event;

/**
 * A page that comes back empty in the *middle* of a run must not cost its items
 * permanently.
 *
 * PROGRESS (2026-09-02) records that the App Store feed returns empty pages
 * intermittently — page=1 answered with 0 entries once and 50 on five retries.
 * The run loop already refuses to treat an empty page as end-of-stream. But the
 * watermark is a high-water mark on published_at, so if the run promotes it
 * after skipping a page, everything that was on the skipped page is older than
 * the new watermark and alreadySeen() rejects it on every subsequent run. The
 * items are not retried, they are erased from the stream.
 *
 * Two runs, three pages, and the middle page empty on the first run only.
 */
final class FlakyMiddlePageConnector implements PlatformConnector
{
    public static int $run = 0;

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $state = SyncCursor::decode($cursor);
        $page = $state->page;

        // Newest first, one item per page, three pages.
        $entries = [
            1 => ['id' => 'newest', 'at' => '2026-08-27T12:00:00+00:00'],
            2 => ['id' => 'middle', 'at' => '2026-08-26T12:00:00+00:00'],
            3 => ['id' => 'oldest', 'at' => '2026-08-25T12:00:00+00:00'],
        ];

        $entry = $entries[$page] ?? null;
        $blank = self::$run === 1 && $page === 2;

        $items = [];
        $pageWatermark = null;

        if ($entry !== null && ! $blank) {
            $pageWatermark = $entry['at'];

            if (! $state->alreadySeen($entry['at'])) {
                $items[] = new ConnectorItem(
                    externalId: $entry['id'],
                    author: 'Racer',
                    body: 'body '.$entry['id'],
                    sourceUrl: null,
                    publishedAt: $entry['at'],
                    rating: null,
                    rawPayload: $entry,
                );
            }
        }

        $caughtUp = $entry !== null && ! $blank && $items === [];
        $reached = $state->pendingAdvancedTo($pageWatermark);
        $hasMore = ! $caughtUp && $page < 3;

        return new ConnectorPage(
            items: $items,
            hasMore: $hasMore,
            nextCursor: $reached->withPage($hasMore ? $page + 1 : 1)->encode(),
            watermark: $pageWatermark,
        );
    }

    public function limits(): ConnectorLimits
    {
        return new ConnectorLimits(maxPagesPerRun: 10, maxConsecutiveEmptyPages: 3);
    }

    public function healthCheck(): ConnectorHealth
    {
        return ConnectorHealth::ok();
    }
}

it('does not lose the items of a page that was transiently empty', function () {
    // Faked because the queue runs sync in tests: without it FeedbackIngested
    // reaches the analysis listener, which calls the real analyzer over HTTP.
    // That service is up in the dev stack and absent in CI, so the test passed
    // locally and failed there with AiServiceUnavailableException. What is under
    // test here is which rows exist, not what happens to them afterwards.
    Event::fake([FeedbackIngested::class]);

    [$company, $integration] = fixtureIntegration();

    FlakyMiddlePageConnector::$run = 1;
    useConnector(new FlakyMiddlePageConnector);
    runFetch($company, $integration);

    // Run 1 sees newest and oldest; middle was blank.
    $afterFirst = asTenant($company, fn () => Feedback::query()->pluck('external_id')->sort()->values()->all());
    expect($afterFirst)->toBe(['newest', 'oldest']);

    // Run 2: the feed is healthy again and page 2 answers.
    FlakyMiddlePageConnector::$run = 2;
    runFetch($company, $integration->fresh());

    $afterSecond = asTenant($company, fn () => Feedback::query()->pluck('external_id')->sort()->values()->all());

    expect($afterSecond)->toBe(['middle', 'newest', 'oldest']);
});
