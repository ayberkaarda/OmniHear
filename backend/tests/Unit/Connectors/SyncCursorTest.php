<?php

use App\Support\Connectors\SyncCursor;

/*
|--------------------------------------------------------------------------
| The opaque cursor behind incremental fetch (spec 6.1)
|--------------------------------------------------------------------------
*/

it('starts at page one with no watermark when there is no cursor', function (?string $cursor) {
    $decoded = SyncCursor::decode($cursor);

    expect($decoded->page)->toBe(1)
        ->and($decoded->watermark)->toBeNull();
})->with([null, '', '   ']);

it('survives a cursor it cannot read instead of wedging the integration', function (string $cursor) {
    // A cursor that cannot be decoded means "start over", which is correct and
    // costs nothing: the unique index turns the re-fetch into zero new rows.
    $decoded = SyncCursor::decode($cursor);

    expect($decoded->page)->toBe(1)
        ->and($decoded->watermark)->toBeNull();
})->with(['not json at all', '[1,2,3]', '"a string"', '{"page":"abc"}', '{"watermark":42}']);

it('round-trips through encode and decode', function () {
    $cursor = new SyncCursor(4, '2026-08-31T09:21:00+00:00');

    $decoded = SyncCursor::decode($cursor->encode());

    expect($decoded->page)->toBe(4)
        ->and($decoded->watermark)->toBe('2026-08-31T09:21:00+00:00');
});

it('encodes small enough for the varchar(255) column', function () {
    $encoded = (new SyncCursor(10, '2026-08-31T09:21:00-07:00'))->encode();

    expect(strlen($encoded))->toBeLessThan(255);
});

it('omits a null watermark from the encoded form', function () {
    expect((new SyncCursor(2))->encode())->toBe('{"page":2}');
});

it('never moves the watermark backwards', function () {
    $cursor = (new SyncCursor(1, '2026-08-31T00:00:00+00:00'))
        ->advancedTo('2026-08-30T00:00:00+00:00');

    expect($cursor->watermark)->toBe('2026-08-31T00:00:00+00:00');
});

it('moves the watermark forwards', function () {
    $cursor = (new SyncCursor(1, '2026-08-30T00:00:00+00:00'))
        ->advancedTo('2026-08-31T00:00:00+00:00');

    expect($cursor->watermark)->toBe('2026-08-31T00:00:00+00:00');
});

it('ignores a candidate watermark it cannot parse', function (?string $candidate) {
    $cursor = (new SyncCursor(1, '2026-08-30T00:00:00+00:00'))->advancedTo($candidate);

    expect($cursor->watermark)->toBe('2026-08-30T00:00:00+00:00');
})->with([null, '', 'yesterday-ish']);

it('compares across timezone offsets rather than lexically', function () {
    // 2026-08-31T09:21:00-07:00 is 16:21Z, so it is *later* than 10:00Z even
    // though it sorts earlier as a string.
    $cursor = new SyncCursor(1, '2026-08-31T10:00:00+00:00');

    expect($cursor->alreadySeen('2026-08-31T09:21:00-07:00'))->toBeFalse()
        ->and($cursor->advancedTo('2026-08-31T09:21:00-07:00')->watermark)
        ->toBe('2026-08-31T09:21:00-07:00');
});

it('treats an item at exactly the watermark as already seen', function () {
    expect((new SyncCursor(1, '2026-08-31T00:00:00+00:00'))->alreadySeen('2026-08-31T00:00:00+00:00'))
        ->toBeTrue();
});

it('never treats an undated item as already seen', function () {
    // The unique index is the safety net for those, not a timestamp comparison
    // that has nothing to compare.
    expect((new SyncCursor(1, '2026-08-31T00:00:00+00:00'))->alreadySeen(null))->toBeFalse();
});

it('treats nothing as seen while there is no watermark', function () {
    expect((new SyncCursor)->alreadySeen('2020-01-01T00:00:00+00:00'))->toBeFalse();
});

it('keeps the watermark when only the page changes', function () {
    $cursor = (new SyncCursor(1, '2026-08-31T00:00:00+00:00'))->withPage(7);

    expect($cursor->page)->toBe(7)
        ->and($cursor->watermark)->toBe('2026-08-31T00:00:00+00:00');
});

it('clamps a page below one', function () {
    expect(SyncCursor::decode('{"page":-4}')->page)->toBe(1);
});

it('parses nothing out of a blank timestamp', function () {
    expect(SyncCursor::parse(null))->toBeNull()
        ->and(SyncCursor::parse(' '))->toBeNull()
        ->and(SyncCursor::parse('definitely not a date'))->toBeNull();
});
