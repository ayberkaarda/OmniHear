<?php

use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\ConnectorHealth;
use App\Support\Connectors\ConnectorItem;
use App\Support\Connectors\ConnectorPage;

/*
|--------------------------------------------------------------------------
| The DTO invariants the ingestion runner is allowed to rely on
|--------------------------------------------------------------------------
*/

it('refuses a page that claims more without saying where to continue', function () {
    // hasMore=true with no cursor would restart the stream from the beginning
    // on every pass — an infinite loop that also re-reads the whole history.
    new ConnectorPage(items: [], hasMore: true, nextCursor: null);
})->throws(InvalidArgumentException::class);

it('allows a final page with no cursor', function () {
    $page = new ConnectorPage(items: [], hasMore: false, nextCursor: null);

    expect($page->hasMore)->toBeFalse()
        ->and($page->isEmpty())->toBeTrue();
});

it('reports an empty page as empty without saying the stream ended', function () {
    $page = new ConnectorPage(items: [], hasMore: true, nextCursor: '{"page":2}');

    expect($page->isEmpty())->toBeTrue()
        ->and($page->hasMore)->toBeTrue();
});

it('is not empty when it carries items', function () {
    $item = new ConnectorItem('id-1', 'A', 'body', null, null, null, []);

    expect((new ConnectorPage([$item], false, null))->isEmpty())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Invariant I5 — a failure can only ever say one of a fixed set of sentences
|--------------------------------------------------------------------------
*/

it('gives every failure reason a safe fixed message', function (ConnectorFailure $failure) {
    $message = $failure->safeMessage();

    expect($message)->toBeString()->not->toBe('')
        ->and($message)->not->toContain('Bearer')
        ->and($message)->not->toContain('token')
        ->and($message)->not->toContain('=');
})->with(ConnectorFailure::cases());

it('never copies the underlying exception message into the connector exception', function () {
    $leak = 'HTTP 401 for Authorization: Bearer sk-live-super-secret-value';

    $exception = ConnectorException::of(ConnectorFailure::InvalidCredentials, new RuntimeException($leak));

    expect($exception->getMessage())->not->toContain('sk-live-super-secret-value')
        ->and($exception->getSafeMessage())->not->toContain('sk-live-super-secret-value')
        ->and($exception->getSafeMessage())->toBe(ConnectorFailure::InvalidCredentials->safeMessage())
        ->and($exception->getPrevious()?->getMessage())->toBe($leak);
});

it('classifies which failures are worth retrying', function () {
    expect(ConnectorFailure::Unreachable->isTransient())->toBeTrue()
        ->and(ConnectorFailure::RateLimited->isTransient())->toBeTrue()
        ->and(ConnectorFailure::InvalidCredentials->isTransient())->toBeFalse()
        ->and(ConnectorFailure::DepthLimitExceeded->isTransient())->toBeFalse()
        ->and(ConnectorFailure::MalformedResponse->isTransient())->toBeFalse()
        ->and(ConnectorFailure::Misconfigured->isTransient())->toBeFalse();
});

it('maps failures onto the published error catalogue', function () {
    expect(ConnectorFailure::InvalidCredentials->apiErrorCode()->value)
        ->toBe('INTEGRATION_INVALID_CREDENTIALS')
        ->and(ConnectorFailure::Unreachable->apiErrorCode()->value)
        ->toBe('INTEGRATION_UNAVAILABLE');
});

it('carries the failure through the exception', function () {
    $exception = ConnectorException::of(ConnectorFailure::RateLimited);

    expect($exception->failure())->toBe(ConnectorFailure::RateLimited)
        ->and($exception->isTransient())->toBeTrue();
});

it('describes health as a safe message or nothing at all', function () {
    expect(ConnectorHealth::ok()->healthy)->toBeTrue()
        ->and(ConnectorHealth::ok()->message())->toBeNull()
        ->and(ConnectorHealth::failing(ConnectorFailure::Misconfigured)->healthy)->toBeFalse()
        ->and(ConnectorHealth::failing(ConnectorFailure::Misconfigured)->message())
        ->toBe(ConnectorFailure::Misconfigured->safeMessage());
});
