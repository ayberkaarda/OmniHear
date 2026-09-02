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

/*
|--------------------------------------------------------------------------
| pendingAdvancedTo — the in-run accumulator, never the watermark itself
|--------------------------------------------------------------------------
*/

it('pendingAdvancedTo keeps the later of two candidates regardless of call order', function () {
    $forward = (new SyncCursor(1))
        ->pendingAdvancedTo('2026-08-30T00:00:00+00:00')
        ->pendingAdvancedTo('2026-08-31T00:00:00+00:00');

    $backward = (new SyncCursor(1))
        ->pendingAdvancedTo('2026-08-31T00:00:00+00:00')
        ->pendingAdvancedTo('2026-08-30T00:00:00+00:00');

    expect($forward->pending)->toBe('2026-08-31T00:00:00+00:00')
        ->and($backward->pending)->toBe('2026-08-31T00:00:00+00:00');
});

it('pendingAdvancedTo is monotonic across repeated calls in any order', function () {
    $cursor = (new SyncCursor(1))
        ->pendingAdvancedTo('2026-08-25T00:00:00+00:00')
        ->pendingAdvancedTo('2026-08-31T00:00:00+00:00')
        ->pendingAdvancedTo('2026-08-20T00:00:00+00:00')
        ->pendingAdvancedTo('2026-08-28T00:00:00+00:00');

    expect($cursor->pending)->toBe('2026-08-31T00:00:00+00:00');
});

it('pendingAdvancedTo ignores a null candidate', function () {
    $cursor = (new SyncCursor(1, null, '2026-08-30T00:00:00+00:00'))->pendingAdvancedTo(null);

    expect($cursor->pending)->toBe('2026-08-30T00:00:00+00:00');
});

it('pendingAdvancedTo ignores an unparseable candidate', function () {
    $cursor = (new SyncCursor(1, null, '2026-08-30T00:00:00+00:00'))->pendingAdvancedTo('not-a-date');

    expect($cursor->pending)->toBe('2026-08-30T00:00:00+00:00');
});

it('pendingAdvancedTo never moves the watermark itself', function () {
    $cursor = (new SyncCursor(1, '2026-08-20T00:00:00+00:00'))
        ->pendingAdvancedTo('2026-08-31T00:00:00+00:00');

    expect($cursor->watermark)->toBe('2026-08-20T00:00:00+00:00')
        ->and($cursor->pending)->toBe('2026-08-31T00:00:00+00:00');
});

/*
|--------------------------------------------------------------------------
| promoted() — end-of-run fold of pending into watermark
|--------------------------------------------------------------------------
*/

it('promoted folds pending into watermark, clears pending, and rewinds to page one', function () {
    $cursor = (new SyncCursor(4, null, '2026-08-31T00:00:00+00:00'))->promoted();

    expect($cursor->page)->toBe(1)
        ->and($cursor->watermark)->toBe('2026-08-31T00:00:00+00:00')
        ->and($cursor->pending)->toBeNull();
});

it('promoted with no pending leaves the watermark untouched but still rewinds the page', function () {
    $cursor = (new SyncCursor(3, '2026-08-31T00:00:00+00:00'))->promoted();

    expect($cursor->page)->toBe(1)
        ->and($cursor->watermark)->toBe('2026-08-31T00:00:00+00:00')
        ->and($cursor->pending)->toBeNull();
});

it('promoted never moves the watermark backwards when it is already later than pending', function () {
    $cursor = (new SyncCursor(3, '2026-08-31T00:00:00+00:00', '2026-08-20T00:00:00+00:00'))->promoted();

    expect($cursor->watermark)->toBe('2026-08-31T00:00:00+00:00')
        ->and($cursor->pending)->toBeNull();
});

it('promoted is idempotent', function () {
    $once = (new SyncCursor(4, null, '2026-08-31T00:00:00+00:00'))->promoted();
    $twice = $once->promoted();

    expect($twice->page)->toBe($once->page)
        ->and($twice->watermark)->toBe($once->watermark)
        ->and($twice->pending)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| encode/decode — round-tripping all three fields, including `pending`
|--------------------------------------------------------------------------
*/

it('round-trips all three fields through encode and decode', function () {
    $cursor = new SyncCursor(4, '2026-08-30T00:00:00+00:00', '2026-08-31T00:00:00+00:00');

    $decoded = SyncCursor::decode($cursor->encode());

    expect($decoded->page)->toBe(4)
        ->and($decoded->watermark)->toBe('2026-08-30T00:00:00+00:00')
        ->and($decoded->pending)->toBe('2026-08-31T00:00:00+00:00');
});

it('round-trips a null pending without fabricating one', function () {
    $cursor = new SyncCursor(2, '2026-08-30T00:00:00+00:00', null);

    $decoded = SyncCursor::decode($cursor->encode());

    expect($decoded->pending)->toBeNull();
});

it('omits a null pending from the encoded form', function () {
    expect((new SyncCursor(2, '2026-08-30T00:00:00+00:00'))->encode())
        ->toBe('{"page":2,"watermark":"2026-08-30T00:00:00+00:00"}');
});

it('decodes a malformed pending field to a safe default rather than throwing', function () {
    // The rest of an otherwise well-formed cursor must not be discarded just
    // because one field is wrong-typed — decode() degrades per-field, not
    // per-document, so an intra-run accumulator glitch cannot wedge the whole
    // integration back to page one with its watermark erased too.
    $decoded = SyncCursor::decode('{"page":2,"watermark":"2026-08-30T00:00:00+00:00","pending":42}');

    expect($decoded->page)->toBe(2)
        ->and($decoded->watermark)->toBe('2026-08-30T00:00:00+00:00')
        ->and($decoded->pending)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The connector-owned token
|--------------------------------------------------------------------------
|
| Zendesk's incrementality is its own opaque cursor, not a timestamp. It has to
| ride in this class rather than in a connector-private encoding, because
| IngestionRunner decodes the connector's cursor and re-encodes it to promote
| the watermark — and anything this class does not know about is dropped on that
| round trip. For Zendesk that would mean restarting the export from start_time
| on every single run, which is the full re-scan spec 6.1 forbids.
|
*/

it('round-trips an opaque token through encode and decode', function () {
    $encoded = (new SyncCursor)->withToken('MTc4ODE2MTE5MC4wfHwxMDAzfA==')->encode();

    expect(SyncCursor::decode($encoded)->token)->toBe('MTc4ODE2MTE5MC4wfHwxMDAzfA==');
});

it('omits a null token from the encoded form', function () {
    expect((new SyncCursor(2))->encode())->toBe('{"page":2}');
});

it('decodes a malformed token to null rather than throwing', function (string $cursor) {
    expect(SyncCursor::decode($cursor)->token)->toBeNull();
})->with(['{"page":1,"token":42}', '{"page":1,"token":""}', '{"page":1,"token":{"a":1}}', '{"page":1}']);

it('keeps the token through every transformation the runner performs', function () {
    $cursor = (new SyncCursor)->withToken('tok-1');

    expect($cursor->withPage(4)->token)->toBe('tok-1')
        ->and($cursor->advancedTo('2026-08-31T00:00:00+00:00')->token)->toBe('tok-1')
        ->and($cursor->pendingAdvancedTo('2026-08-31T00:00:00+00:00')->token)->toBe('tok-1')
        // promoted() is the one the runner calls at the end of a complete run.
        ->and($cursor->pendingAdvancedTo('2026-08-31T00:00:00+00:00')->promoted()->token)->toBe('tok-1');
});

it('replaces rather than merges a token', function () {
    expect((new SyncCursor)->withToken('tok-1')->withToken('tok-2')->token)->toBe('tok-2')
        ->and((new SyncCursor)->withToken('tok-1')->withToken(null)->token)->toBeNull();
});

it('encodes a token alongside every other field and still fits varchar(255)', function () {
    $encoded = (new SyncCursor(10, '2026-08-31T09:21:00-07:00', '2026-09-01T09:21:00-07:00'))
        ->withToken(str_repeat('x', 120))
        ->encode();

    // 120 is ZendeskConnector::MAX_TOKEN_LENGTH, the longest token that
    // connector will accept, and this is the fullest cursor that can hold one:
    // page, watermark, pending and token together.
    expect(strlen($encoded))->toBeLessThan(255);
});
