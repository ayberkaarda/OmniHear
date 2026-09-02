<?php

use App\Support\Payments\CheckoutReference;
use App\Support\Payments\PaidPlans;
use App\Support\Payments\PaymentGatewayManager;
use App\Support\Payments\PaymentProviderException;
use App\Support\Payments\Stripe\StripeGateway;

/*
|--------------------------------------------------------------------------
| The pieces the webhook tenant resolution rests on
|--------------------------------------------------------------------------
*/

it('round-trips a company id through a checkout reference', function () {
    $reference = CheckoutReference::for(42);

    expect($reference)->toMatch('/^omnihear-42-[0-9a-f]{16}$/')
        ->and(CheckoutReference::companyId($reference))->toBe(42);
});

it('produces a different reference every time', function () {
    $references = array_map(fn () => CheckoutReference::for(1), range(1, 20));

    expect(array_unique($references))->toHaveCount(20);
});

it('refuses to read a company id out of anything it did not write', function (mixed $value) {
    // Fed straight from an unauthenticated payload, so it must never throw and
    // never guess. A bare integer in particular is rejected: accepting one would
    // let a plausible-looking body pick its own victim tenant.
    expect(CheckoutReference::companyId($value))->toBeNull();
})->with([
    'empty' => '',
    'bare integer' => '7',
    'integer type' => 7,
    'null' => null,
    // Doubly wrapped: a dataset entry that is an array is the argument *list*,
    // so a single-element array here would pass the string, not the array.
    'array' => [['omnihear-1-0f1e2d3c4b5a6978']],
    'no random part' => 'omnihear-1-',
    'random part too short' => 'omnihear-1-0f1e2d3c',
    'random part not hex' => 'omnihear-1-zzzzzzzzzzzzzzzz',
    'zero company' => 'omnihear-0-0f1e2d3c4b5a6978',
    'wrong prefix' => 'someoneelse-1-0f1e2d3c4b5a6978',
    'trailing junk' => 'omnihear-1-0f1e2d3c4b5a6978x',
    'leading junk' => 'xomnihear-1-0f1e2d3c4b5a6978',
]);

it('sells every plan except free', function () {
    expect(PaidPlans::all())->toBe(['pro'])
        ->and(PaidPlans::isPaid('pro'))->toBeTrue()
        ->and(PaidPlans::isPaid('free'))->toBeFalse()
        ->and(PaidPlans::isPaid('enterprise'))->toBeFalse()
        ->and(PaidPlans::isPaid(null))->toBeFalse();
});

it('returns no paid plans when the quota config is unreadable', function () {
    config(['quota.plans' => null]);

    expect(PaidPlans::all())->toBe([]);
});

it('resolves each provider to its gateway', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->for('stripe')->provider())->toBe('stripe')
        ->and($manager->for('iyzico')->provider())->toBe('iyzico');
});

it('refuses an unknown provider', function () {
    app(PaymentGatewayManager::class)->for('paypal');
})->throws(InvalidArgumentException::class);

it('keeps the provider failure reason out of the client response', function () {
    // The reason is for our logs; the wire always carries the catalogue message
    // so an unauthenticated caller learns nothing about our configuration.
    $exception = PaymentProviderException::notConfigured(StripeGateway::PROVIDER, 'secret');

    expect($exception->provider)->toBe('stripe')
        ->and($exception->reason)->toBe('not_configured:secret')
        ->and($exception->status())->toBe(502)
        ->and($exception->getMessage())->toBe('The payment provider returned an error. No charge was made.');
});
