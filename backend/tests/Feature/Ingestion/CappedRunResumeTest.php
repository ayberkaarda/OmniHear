<?php

use App\Events\FeedbackIngested;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Connectors\ConnectorHealth;
use App\Support\Connectors\ConnectorItem;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\ConnectorPage;
use App\Support\Connectors\PlatformConnector;
use App\Support\Connectors\SyncCursor;
use Illuminate\Support\Facades\Event;

/**
 * A run that the runner's own page cap (ConnectorLimits::maxPagesPerRun) cuts
 * short, while the connector still has more to give, must resume rather than
 * lose ground: the watermark must not advance (that would bury the unreached
 * pages below it forever, exactly the bug EmptyMiddlePageTest.php guards
 * against for an empty page) and the stored cursor must point at the next
 * page rather than restart the feed from page one.
 *
 * The scenario is deliberately distinct from an empty-page scenario: every
 * page here carries a real item, so any loss is directly visible as a missing
 * external_id rather than inferred from a watermark value.
 *
 * A fixed, newest-first script of pages. An entry of `null` means the page
 * answers empty; hasMore reflects whether the script has any page left, which
 * is independent of the runner's own cap — exactly the two knobs this file
 * needs to turn separately.
 */
final class ScriptedPagedConnector implements PlatformConnector
{
    public const NEWEST = '2026-08-27T12:00:00+00:00';

    public const MIDDLE = '2026-08-26T12:00:00+00:00';

    public const OLDEST = '2026-08-25T12:00:00+00:00';

    public int $calls = 0;

    /** @param  list<array{id:string,at:string}|null>  $pages */
    public function __construct(
        private readonly array $pages,
        private readonly ConnectorLimits $connectorLimits,
    ) {}

    public function fetchPage(?string $cursor): ConnectorPage
    {
        $this->calls++;

        $state = SyncCursor::decode($cursor);
        $page = $state->page;
        $entry = $this->pages[$page - 1] ?? null;

        $items = [];
        $pageWatermark = null;

        if ($entry !== null) {
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

        $hasMore = $page < count($this->pages);
        $reached = $state->pendingAdvancedTo($pageWatermark);

        return new ConnectorPage(
            items: $items,
            hasMore: $hasMore,
            nextCursor: $reached->withPage($hasMore ? $page + 1 : 1)->encode(),
            watermark: $pageWatermark,
        );
    }

    public function limits(): ConnectorLimits
    {
        return $this->connectorLimits;
    }

    public function healthCheck(): ConnectorHealth
    {
        return ConnectorHealth::ok();
    }
}

/** @return list<string> */
function cappedRunExternalIds(Company $company): array
{
    return asTenant($company, fn () => Feedback::query()->pluck('external_id')->sort()->values()->all());
}

function cappedRunCursor(Integration $integration): SyncCursor
{
    return SyncCursor::decode($integration->fresh()->sync_cursor);
}

it('does not advance the watermark while the cap cuts a run short, and resumes at the next page across repeated capped runs', function () {
    Event::fake([FeedbackIngested::class]);

    // Three real pages, but the runner is only allowed one page per run —
    // so every run here is capped while the connector still has more.
    $connector = new ScriptedPagedConnector(
        pages: [
            ['id' => 'newest', 'at' => ScriptedPagedConnector::NEWEST],
            ['id' => 'middle', 'at' => ScriptedPagedConnector::MIDDLE],
            ['id' => 'oldest', 'at' => ScriptedPagedConnector::OLDEST],
        ],
        connectorLimits: new ConnectorLimits(maxPagesPerRun: 1, maxConsecutiveEmptyPages: 3),
    );

    [$company, $integration] = fixtureIntegration();
    useConnector($connector);

    runFetch($company, $integration);

    $afterFirst = cappedRunCursor($integration);
    expect(cappedRunExternalIds($company))->toBe(['newest'])
        ->and($afterFirst->watermark)->toBeNull()
        ->and($afterFirst->page)->toBe(2);

    runFetch($company, $integration->fresh());

    $afterSecond = cappedRunCursor($integration);
    expect(cappedRunExternalIds($company))->toBe(['middle', 'newest'])
        ->and($afterSecond->watermark)->toBeNull()
        ->and($afterSecond->page)->toBe(3);

    // Third run: the connector finally answers its last page (hasMore=false).
    // Nothing was lost across two capped runs, and promotion happens now,
    // exactly once.
    runFetch($company, $integration->fresh());

    $afterThird = cappedRunCursor($integration);
    expect(cappedRunExternalIds($company))->toBe(['middle', 'newest', 'oldest'])
        ->and($afterThird->watermark)->toBe(ScriptedPagedConnector::NEWEST)
        ->and($afterThird->page)->toBe(1);
});

it('does not lose items when a run is capped in the same run an earlier page came back empty', function () {
    Event::fake([FeedbackIngested::class]);

    // Page 1 has an item, page 2 is genuinely empty, page 3 has the oldest
    // item. The cap (2 pages/run) lands exactly on the empty page, so the
    // first run carries both risk factors EmptyMiddlePageTest and this file
    // cover separately: an empty page AND a cap, together.
    $connector = new ScriptedPagedConnector(
        pages: [
            ['id' => 'a', 'at' => ScriptedPagedConnector::NEWEST],
            null,
            ['id' => 'c', 'at' => ScriptedPagedConnector::OLDEST],
        ],
        connectorLimits: new ConnectorLimits(maxPagesPerRun: 2, maxConsecutiveEmptyPages: 3),
    );

    [$company, $integration] = fixtureIntegration();
    useConnector($connector);

    runFetch($company, $integration);

    $afterFirst = cappedRunCursor($integration);
    expect(cappedRunExternalIds($company))->toBe(['a'])
        ->and($afterFirst->watermark)->toBeNull()
        ->and($afterFirst->page)->toBe(3);

    // Second run is healthy end to end: it reaches page 3 and the connector
    // reports hasMore=false. If the first (capped + empty) run had wrongly
    // promoted the watermark to "a"'s timestamp, "c" — older than "a" — would
    // now be rejected by alreadySeen() and silently lost. It is not: nothing
    // in the cap+empty run was allowed to move the watermark.
    runFetch($company, $integration->fresh());

    $afterSecond = cappedRunCursor($integration);
    expect(cappedRunExternalIds($company))->toBe(['a', 'c'])
        ->and($afterSecond->watermark)->toBe(ScriptedPagedConnector::NEWEST)
        ->and($afterSecond->page)->toBe(1);
});

it('promotes when the cap lands exactly on the connector\'s own last page, because $capped alone does not mean incomplete', function () {
    Event::fake([FeedbackIngested::class]);

    // Mirrors the App Store connector: its page-depth ceiling (10) and the
    // runner's own maxPagesPerRun can coincide on the same iteration in which
    // hasMore is legitimately false. That run is complete and must promote,
    // even though pages fetched === maxPagesPerRun so $capped is also true.
    $connector = new ScriptedPagedConnector(
        pages: [
            ['id' => 'x', 'at' => ScriptedPagedConnector::NEWEST],
            ['id' => 'y', 'at' => ScriptedPagedConnector::OLDEST],
        ],
        connectorLimits: new ConnectorLimits(maxPagesPerRun: 2, maxConsecutiveEmptyPages: 3),
    );

    [$company, $integration] = fixtureIntegration();
    useConnector($connector);

    runFetch($company, $integration);

    $after = cappedRunCursor($integration);
    expect(cappedRunExternalIds($company))->toBe(['x', 'y'])
        ->and($after->watermark)->toBe(ScriptedPagedConnector::NEWEST)
        ->and($after->page)->toBe(1)
        ->and($connector->calls)->toBe(2);
});
