<?php

use App\Broadcasting\CompanyChannel;
use App\Models\Company;
use App\Models\User;

/**
 * Invariant I1 on the websocket surface (spec 6.5,
 * docs/contracts/wave2-seams.md section 4).
 *
 * The HTTP tests below run against the `redis` broadcaster rather than the
 * `null` one the suite defaults to. That is not incidental: NullBroadcaster's
 * auth() is an empty method, so it authorizes *everything*, and a test written
 * against it would pass no matter what routes/channels.php said. RedisBroadcaster
 * runs the real verifyUserCanAccessChannel() path; nothing in it reaches Redis
 * during authorization.
 */
beforeEach(function () {
    // The connection has to be defined here as well as selected: Laravel's
    // default broadcasting config ships reverb, pusher, ably, log and null, but
    // no redis entry, so selecting it alone fails with "Broadcast connection
    // [redis] is not defined". Nothing below reaches the Redis server —
    // RedisBroadcaster only touches it when publishing, not when authorizing.
    config([
        'broadcasting.connections.redis' => ['driver' => 'redis', 'connection' => 'default'],
        'broadcasting.default' => 'redis',
    ]);

    // Channels are registered on the broadcaster instance that was default at
    // boot, not on the manager: Broadcast::channel() reaches the driver through
    // BroadcastManager::__call. Switching the default afterwards therefore hands
    // back a fresh RedisBroadcaster with no channels at all, and every
    // authorization attempt answers 403 — including the ones that should succeed,
    // which makes the two negative tests here pass for the wrong reason.
    //
    // Re-running the real routes/channels.php is what registers them on the new
    // broadcaster. Re-declaring the channel inline here would test this file
    // instead of the one that ships.
    require base_path('routes/channels.php');
});

it('lets a user subscribe to their own company channel', function () {
    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-company.'.$company->id,
        ])
        ->assertOk();
});

it('rejects a user whose company_id does not match the channel', function () {
    [, $user] = tenant();
    [$other] = tenant();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-company.'.$other->id,
        ])
        ->assertForbidden();
});

it('rejects an unauthenticated subscription attempt', function () {
    [$company] = tenant();

    $this->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-company.'.$company->id,
    ])->assertUnauthorized();
});

it('rejects a channel that is not registered at all', function () {
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-everything',
        ])
        ->assertForbidden();
});

it('compares the tenant discriminator, not the string', function () {
    // The channel segment arrives as a string off the wire while company_id is
    // an integer. A strict === between them would deny every legitimate
    // subscription; a bare (int) cast would accept "12-anything" for company 12.
    [$company, $user] = tenant();
    $channel = new CompanyChannel;

    $foreign = User::factory()->for(Company::factory()->create())->create();

    expect($channel->join($user, (string) $company->id))->toBeTrue()
        ->and($channel->join($user, $company->id))->toBeTrue()
        ->and($channel->join($foreign, (string) $company->id))->toBeFalse()
        ->and($channel->join($user, $company->id.'-anything'))->toBeFalse();
});

it('rejects a subscription from a user who has not verified their address', function () {
    // withBroadcasting() in bootstrap/app.php builds its own middleware array
    // and never inherits the api group's defaults, so `verified` has to be
    // listed there by hand. Without it this was the one authenticated /api/v1
    // route an unverified user could reach - and it hands out every
    // FeedbackAnalyzed and QuotaThresholdReached event for the company.
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->unverified()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-company.'.$company->id,
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});
