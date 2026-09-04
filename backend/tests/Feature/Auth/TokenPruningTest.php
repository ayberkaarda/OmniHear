<?php

use App\Models\Company;
use App\Models\User;
use App\Support\Auth\TokenAbility;
use App\Support\Auth\TokenLifetime;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| personal_access_tokens does not grow forever
|--------------------------------------------------------------------------
|
| Sanctum ships `sanctum:prune-expired` and nothing scheduled it, so every row
| any login had ever written was still in the table. W10 did not create that;
| it raised the rate, because a correct password from a user with a second
| factor now writes a five-minute challenge row that is deleted on success and
| on exhausting the attempts — but not when the user abandons the flow.
|
*/

function pruneEvent(): ScheduledEvent
{
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => $event->description === 'auth:prune-expired-tokens');

    expect($events)->toHaveCount(1);

    return $events->first();
}

it('is scheduled daily and will not overlap itself', function () {
    expect(pruneEvent()->expression)->toBe('0 0 * * *')
        ->and(pruneEvent()->withoutOverlapping)->toBeTrue();
});

it('removes an abandoned challenge token once the retention window has passed', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();

    $abandoned = $user->createToken('two-factor-challenge', TokenAbility::challenge(), TokenLifetime::twoFactorChallenge())
        ->accessToken;

    // Expired five minutes in, but still retained: a credential that stopped
    // working an hour ago is evidence, and the answer to "why did my session
    // end?" is a row that is expired and still present.
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000)->addHours(20));

    $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

    expect(PersonalAccessToken::query()->whereKey($abandoned->id)->exists())->toBeTrue();

    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000)->addHours(25));

    $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

    expect(PersonalAccessToken::query()->whereKey($abandoned->id)->exists())->toBeFalse();

    Carbon::setTestNow();
});

it('leaves a live credential of every kind alone', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();

    $session = $user->createToken('laptop', TokenAbility::session(), TokenLifetime::session())->accessToken;
    $apiKey = $user->createToken('ci-runner', TokenAbility::api(), TokenLifetime::apiKey())->accessToken;
    $challenge = $user->createToken('two-factor-challenge', TokenAbility::challenge(), TokenLifetime::twoFactorChallenge())
        ->accessToken;

    $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

    expect(PersonalAccessToken::query()->whereKey($session->id)->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->whereKey($apiKey->id)->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->whereKey($challenge->id)->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('reaches a legacy wildcard row that carries no expiry at all', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1700000000));

    $company = Company::factory()->create();
    $user = User::factory()->for($company)->owner()->create();

    // The shape a pre-F5 login left behind: ['*'] abilities, expires_at null.
    // The `expires_at <` half of the command cannot see it; the second half,
    // which is only active because config('sanctum.expiration') is set, is what
    // finally clears these out once they are past the absolute ceiling.
    $legacy = $user->createToken('old-web')->accessToken;
    $legacy->forceFill(['expires_at' => null])->save();

    $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

    expect(PersonalAccessToken::query()->whereKey($legacy->id)->exists())->toBeTrue();

    Carbon::setTestNow(
        Carbon::createFromTimestampUTC(1700000000)
            ->addMinutes((int) config('sanctum.expiration'))
            ->addHours(25),
    );

    $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

    expect(PersonalAccessToken::query()->whereKey($legacy->id)->exists())->toBeFalse();

    Carbon::setTestNow();
});
