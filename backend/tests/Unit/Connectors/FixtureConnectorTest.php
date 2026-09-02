<?php

use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorLimits;
use App\Support\Connectors\FixtureConnector;
use App\Support\Connectors\SyncCursor;
use Tests\Support\PlatformFixture;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The credential-free connector every ingestion test runs against
|--------------------------------------------------------------------------
|
| One file is one page, ordered by filename, newest first — the same shape as a
| real paged feed, so the cursor semantics exercised here are the real ones.
|
*/

function fixtureConnector(string $set = 'default', int $maxPages = 10): FixtureConnector
{
    return new FixtureConnector(
        directory: PlatformFixture::path('fixture', $set),
        limits: new ConnectorLimits($maxPages, 3),
    );
}

/**
 * @return list<array<string, mixed>>
 */
function fixturePage(string $file): array
{
    /** @var list<array<string, mixed>> $decoded */
    $decoded = json_decode(PlatformFixture::raw('fixture', 'default/'.$file), true, 32, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('reads the first page and reports there is more', function () {
    $expected = fixturePage('page-1.json');

    $page = fixtureConnector()->fetchPage(null);

    expect($page->items)->toHaveCount(count($expected))
        ->and($page->hasMore)->toBeTrue()
        ->and(SyncCursor::decode($page->nextCursor)->page)->toBe(2);
});

it('maps the documented item fields', function () {
    $expected = fixturePage('page-1.json')[0];

    $item = fixtureConnector()->fetchPage(null)->items[0];

    expect($item->externalId)->toBe($expected['external_id'])
        ->and($item->author)->toBe($expected['author'])
        ->and($item->body)->toBe($expected['body'])
        ->and($item->sourceUrl)->toBe($expected['source_url'])
        ->and($item->publishedAt)->toBe($expected['published_at'])
        ->and($item->rating)->toBe($expected['rating'])
        ->and($item->rawPayload)->toBe($expected);
});

it('ends the run on the last file and rewinds the cursor for the next one', function () {
    $page = fixtureConnector()->fetchPage('{"page":2}');

    expect($page->items)->toHaveCount(count(fixturePage('page-2.json')))
        ->and($page->hasMore)->toBeFalse()
        ->and(SyncCursor::decode($page->nextCursor)->page)->toBe(1)
        // Promotion moved to the runner, which is the only party that knows
        // whether an earlier page of this run came back empty. The connector
        // reports how far it reached in `pending`; `watermark` is still the
        // value the run started from.
        ->and(SyncCursor::decode($page->nextCursor)->pending)->not->toBeNull();
});

it('returns an empty final page past the last file', function () {
    $page = fixtureConnector()->fetchPage('{"page":9}');

    expect($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse();
});

it('skips everything at or below the watermark', function () {
    $newest = collect(fixturePage('page-1.json'))
        ->pluck('published_at')
        ->sortByDesc(fn (string $timestamp) => SyncCursor::parse($timestamp)?->getTimestamp())
        ->first();

    $page = fixtureConnector()->fetchPage(json_encode(['page' => 1, 'watermark' => $newest]));

    expect($page->items)->toBeEmpty()
        ->and($page->hasMore)->toBeFalse();
});

it('keeps items newer than the watermark and stops at the first one it has seen', function () {
    $timestamps = collect(fixturePage('page-1.json'))
        ->pluck('published_at')
        ->sortByDesc(fn (string $timestamp) => SyncCursor::parse($timestamp)?->getTimestamp())
        ->values();

    $watermark = $timestamps->last();
    $expected = $timestamps
        ->filter(fn (string $timestamp) => SyncCursor::parse($timestamp) > SyncCursor::parse($watermark))
        ->count();

    $page = fixtureConnector()->fetchPage(json_encode(['page' => 1, 'watermark' => $watermark]));

    expect($page->items)->toHaveCount($expected)
        ->and($page->hasMore)->toBeFalse();
});

it('publishes the ceilings the runner enforces on its behalf', function () {
    $limits = fixtureConnector(maxPages: 4)->limits();

    expect($limits->maxPagesPerRun)->toBe(4)
        ->and($limits->maxConsecutiveEmptyPages)->toBe(3);
});

it('refuses a fixture set that is not there', function () {
    $connector = fixtureConnector('does-not-exist');

    try {
        $connector->fetchPage(null);
        $failure = null;
    } catch (ConnectorException $e) {
        $failure = $e->failure();
    }

    expect($failure)->toBe(ConnectorFailure::Misconfigured)
        ->and($connector->healthCheck()->healthy)->toBeFalse();
});

it('reports itself healthy when its set is present', function () {
    expect(fixtureConnector()->healthCheck()->healthy)->toBeTrue();
});

it('refuses a page file that is not a list of items', function () {
    $directory = sys_get_temp_dir().'/omnihear-fixture-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/page-1.json', '{"not":"a list"}');

    $connector = new FixtureConnector($directory, new ConnectorLimits(10, 3));

    try {
        $connector->fetchPage(null);
        $failure = null;
    } catch (ConnectorException $e) {
        $failure = $e->failure();
    }

    unlink($directory.'/page-1.json');
    rmdir($directory);

    expect($failure)->toBe(ConnectorFailure::MalformedResponse);
});

it('refuses a page file that is not JSON', function () {
    $directory = sys_get_temp_dir().'/omnihear-fixture-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/page-1.json', 'not json at all');

    $connector = new FixtureConnector($directory, new ConnectorLimits(10, 3));

    try {
        $connector->fetchPage(null);
        $failure = null;
    } catch (ConnectorException $e) {
        $failure = $e->failure();
    }

    unlink($directory.'/page-1.json');
    rmdir($directory);

    expect($failure)->toBe(ConnectorFailure::MalformedResponse);
});

it('skips an entry with no external id', function () {
    $directory = sys_get_temp_dir().'/omnihear-fixture-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/page-1.json', json_encode([
        ['body' => 'no id here'],
        ['external_id' => 'keeper', 'body' => 'kept'],
    ]));

    $page = (new FixtureConnector($directory, new ConnectorLimits(10, 3)))->fetchPage(null);

    unlink($directory.'/page-1.json');
    rmdir($directory);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->externalId)->toBe('keeper');
});
