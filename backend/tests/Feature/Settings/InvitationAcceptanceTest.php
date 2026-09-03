<?php

use App\Http\Resources\Api\V1\InvitationResource;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\Audit\AuditAction;
use App\Support\Auth\TokenAbility;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Accepting a team invitation — docs/contracts/settings-api.md section 3a
|--------------------------------------------------------------------------
|
| Before these two endpoints existed, `POST /settings/team/invitations` wrote a
| row, hashed a token and mailed nobody, and nothing consumed the token: a
| company could never acquire a second user, which put the whole of spec 8's
| role model out of reach of the running product.
|
| The two properties this file exists to hold on to:
|
|  - expired, already-accepted and unknown tokens are one indistinguishable
|    404, so the token space cannot be probed;
|  - the accepted user is created **verified**, because the token was mailed to
|    that address and that is the same proof POST /auth/email/verify asks for.
|
*/

/**
 * An invitation plus the plaintext token that opens it.
 *
 * Written through the model rather than the factory so the hash and the
 * plaintext cannot drift: the factory stores a hash of a token it throws away,
 * which is right for every other test and useless here.
 *
 * @return array{0: Invitation, 1: string, 2: Company}
 */
function invitationWithToken(array $attributes = [], ?Company $company = null): array
{
    $company ??= Company::factory()->create();
    $plain = Str::random(48);

    $invitation = asTenant($company, fn () => Invitation::query()->create(array_merge([
        'email' => 'invitee@example.test',
        'role' => User::ROLE_MEMBER,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->addDays(7),
        'accepted_at' => null,
    ], $attributes)));

    return [$invitation, $plain, $company];
}

/*
|--------------------------------------------------------------------------
| The mail — an invitation nothing delivers is a button that leads nowhere
|--------------------------------------------------------------------------
*/

it('mails the invitation to the invited address with a working link', function () {
    Notification::fake();

    [$company, $owner] = tenant();
    $owner->forceFill(['name' => 'Ada Lovelace'])->save();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'new@example.test', 'role' => 'admin'])
        ->assertStatus(201);

    Notification::assertSentOnDemand(
        InvitationNotification::class,
        function (InvitationNotification $notification, array $channels, object $notifiable) use ($company): bool {
            expect($channels)->toBe(['mail'])
                ->and($notifiable->routes['mail'])->toBe('new@example.test');

            $mail = $notification->toMail($notifiable);

            // The link points at the SPA page, not at an API endpoint: the
            // recipient needs somewhere to type a name and a password.
            expect($notification->acceptUrl())
                ->toStartWith(rtrim((string) config('app.frontend_url'), '/').'/auth/accept-invitation?token=')
                ->and($mail->actionUrl)->toBe($notification->acceptUrl())
                ->and($mail->subject)->toContain($company->name);

            // And the token in it is the one that opens the row.
            parse_str((string) parse_url($notification->acceptUrl(), PHP_URL_QUERY), $query);

            $matches = asTenant($company, fn (): bool => Invitation::query()
                ->where('token_hash', hash('sha256', $query['token']))
                ->exists());

            return $matches;
        },
    );
});

it('still names the company when the inviter is gone', function () {
    // invitations.invited_by is nullOnDelete: removing a teammate must not
    // remove the invitations they sent, so the mail has to read sensibly with
    // no sender to attribute it to.
    $mail = (new InvitationNotification('plain-token', 'Acme Industries', 'member', null))
        ->toMail(Notification::route('mail', 'invitee@example.test'));

    expect($mail->subject)->toContain('Acme Industries')
        ->and(implode(' ', $mail->introLines))->toContain('Acme Industries')
        ->and($mail->actionUrl)->toContain('/auth/accept-invitation?token=plain-token');
});

it('never puts the plaintext token on the queue', function () {
    // The row stores only a SHA-256 so the plaintext exists in exactly one
    // place: the message. A ShouldQueue notification would serialize it into
    // Redis, where nothing hides it (invariant I5).
    expect(new InvitationNotification('plain', 'Acme', 'member', null))
        ->not->toBeInstanceOf(ShouldQueue::class);
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/invitations/{token}
|--------------------------------------------------------------------------
*/

it('describes a pending invitation to an anonymous caller', function () {
    [$invitation, $plain, $company] = invitationWithToken(['role' => User::ROLE_ADMIN]);

    $this->getJson('/api/v1/invitations/'.$plain)
        ->assertOk()
        ->assertExactJson([
            'invitation' => [
                'email' => 'invitee@example.test',
                'company_name' => $company->name,
                'role' => 'admin',
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ]);
});

it('tells the holder of a token nothing else about the tenant', function () {
    [$invitation, $plain, $company] = invitationWithToken();

    $body = $this->getJson('/api/v1/invitations/'.$plain)->assertOk()->json('invitation');

    // The name is what a human needs to decide whether to accept. The tenant's
    // id, its plan, its quota and the row's own id are not. Asserted as an
    // exact key list rather than as absent substrings: an id is a small
    // integer and turns up inside a timestamp by coincidence, which would make
    // the substring form pass or fail depending on how many rows the test
    // happened to create.
    expect(array_keys($body))->toBe(['email', 'company_name', 'role', 'expires_at'])
        ->and($body['company_name'])->toBe($company->name)
        ->and($body)->not->toHaveKey('id')
        ->and($body)->not->toHaveKey('company_id')
        ->and($body)->not->toHaveKey('token_hash')
        ->and($body)->not->toHaveKey('accepted_at');

    // The full resource a signed-in teammate gets does carry the row id; this
    // one must not have quietly become that.
    expect((new InvitationResource($invitation))->resolve())
        ->toHaveKey('id');
});

it('answers 404 identically for an unknown, an expired and a spent token', function (Closure $make) {
    $plain = $make();

    $this->getJson('/api/v1/invitations/'.$plain)
        ->assertStatus(404)
        ->assertExactJson(['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.']);
})->with([
    'unknown' => [fn () => Str::random(48)],
    'expired' => [function () {
        [, $plain] = invitationWithToken(['expires_at' => now()->subMinute()]);

        return $plain;
    }],
    'already accepted' => [function () {
        [, $plain] = invitationWithToken(['accepted_at' => now()->subMinute()]);

        return $plain;
    }],
]);

/*
|--------------------------------------------------------------------------
| POST /api/v1/invitations/{token}/accept
|--------------------------------------------------------------------------
*/

it('creates the user in the inviting company at the invited role', function () {
    [$invitation, $plain, $company] = invitationWithToken(['role' => User::ROLE_ADMIN]);

    $response = $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(201)
        ->assertJsonStructure(['token', 'user', 'company'])
        ->assertJsonPath('user.email', 'invitee@example.test')
        ->assertJsonPath('user.name', 'Grace Hopper')
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonPath('user.company_id', $company->id)
        ->assertJsonPath('company.id', $company->id);

    $user = User::query()->where('email', 'invitee@example.test')->firstOrFail();

    expect($user->company_id)->toBe($company->id)
        ->and($user->role)->toBe('admin')
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    // The same authenticated state POST /auth/register lands in, from the
    // other door — including a *session* token rather than a wildcard, so
    // /auth/tokens and /settings/api-keys stay disjoint.
    $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);

    expect($user->tokens()->first()->abilities)->toBe(TokenAbility::session());
});

it('creates the accepted user already verified', function () {
    [, $plain] = invitationWithToken();

    $token = $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(201)->json('token');

    $user = User::query()->where('email', 'invitee@example.test')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull();

    // Which is the point: everything a tenant does with its data sits behind
    // `verified`, so an unverified accept would leave the new teammate signed
    // in and unable to reach a single screen.
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/feedbacks')
        ->assertOk();
});

it('takes the address from the row and not from the request body', function () {
    [, $plain] = invitationWithToken();

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'email' => 'attacker@example.test',
        'role' => 'owner',
        'company_id' => 999,
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(201)
        ->assertJsonPath('user.email', 'invitee@example.test')
        ->assertJsonPath('user.role', 'member');

    expect(User::query()->where('email', 'attacker@example.test')->exists())->toBeFalse();
});

it('refuses to spend the same token twice', function () {
    [, $plain] = invitationWithToken();

    $body = [
        'name' => 'Grace Hopper',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ];

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', $body)->assertStatus(201);

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', $body)
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect(User::query()->where('email', 'invitee@example.test')->count())->toBe(1);
});

it('loses the race rather than the database, when the row is spent mid-request', function () {
    // accept() looks the invitation up once without a lock, then re-reads it
    // under `for update` inside the transaction. The second read is not
    // ceremony: two clicks on the same link are two requests, both pass the
    // first check, and without the locked re-check the loser would hit the
    // users.email unique index and surface as a 500.
    //
    // The race is forced deterministically by spending the row on the way out
    // of the first read, which is exactly the window the lock closes.
    [$invitation, $plain] = invitationWithToken();
    $raced = false;

    Invitation::retrieved(function (Invitation $model) use (&$raced): void {
        if ($raced) {
            return;
        }
        $raced = true;
        // Stands in for the other request, which has its own tenant context;
        // this listener runs inside the public lookup, which has none.
        Invitation::query()
            ->withoutGlobalScope(CompanyScope::class) // tenant-scope: bypass-ok test double for a concurrent request that carries its own context
            ->whereKey($model->getKey())
            ->update(['accepted_at' => now()]);
    });

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    // The loser creates nobody, and the winner's row is untouched.
    expect(User::query()->where('email', 'invitee@example.test')->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('answers 404 on accept for an expired token and creates nobody', function () {
    [, $plain] = invitationWithToken(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(404);

    expect(User::query()->where('email', 'invitee@example.test')->exists())->toBeFalse();
});

it('validates the password before it will create anything', function () {
    [, $plain] = invitationWithToken();

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'password' => 'short',
        'password_confirmation' => 'nomatch',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['password']]);

    expect(User::query()->where('email', 'invitee@example.test')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The cross-tenant collision the schema forces
|--------------------------------------------------------------------------
|
| users.email is globally unique, so an address with an account in *any*
| tenant cannot get a second one. Both doors have to say so, and neither may
| move the existing account.
|
*/

it('refuses an accept for an address that already has an account anywhere', function () {
    $other = Company::factory()->create();
    $existing = User::factory()->for($other)->create(['email' => 'invitee@example.test']);

    // The row predates the fix on the invite side, which is exactly the state
    // a database in the wild is in.
    [$invitation, $plain] = invitationWithToken();

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['email']]);

    // The existing account is untouched — re-pointing it at the inviting
    // company would be cross-tenant account takeover.
    expect($existing->fresh()->company_id)->toBe($other->id)
        ->and($existing->fresh()->name)->toBe($existing->name)
        // And the invitation stays open: the collision is a different problem
        // from a bad token, and closing the row removes the only way to fix it.
        ->and($invitation->fresh()->accepted_at)->toBeNull();

    $this->getJson('/api/v1/invitations/'.$plain)->assertOk();
});

it('refuses to issue an invitation for an address that has an account in another tenant', function () {
    Notification::fake();

    $other = Company::factory()->create();
    User::factory()->for($other)->create(['email' => 'taken@example.test']);
    [, $owner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'taken@example.test', 'role' => 'member'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    // No row, and no mail to an address that could never have used it.
    Notification::assertNothingSent();
    expect(Invitation::query()->withoutGlobalScope(CompanyScope::class)->count())->toBe(0); // tenant-scope: bypass-ok asserting the row exists in no tenant at all
});

it('gives the same refusal whether the collision is inside the tenant or outside it', function () {
    // This is the property that keeps the global check from becoming account
    // enumeration: an inviter learns "you cannot use this address", never
    // "this address has an account in a company you cannot see".
    [$company, $owner] = tenant();
    User::factory()->for($company)->create(['email' => 'inside@example.test']);
    User::factory()->for(Company::factory()->create())->create(['email' => 'outside@example.test']);

    $inside = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'inside@example.test', 'role' => 'member'])
        ->assertStatus(422);

    $outside = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'outside@example.test', 'role' => 'member'])
        ->assertStatus(422);

    expect($outside->json('errors.email'))->toBe($inside->json('errors.email'))
        ->and($outside->json('code'))->toBe($inside->json('code'));
});

/*
|--------------------------------------------------------------------------
| The outbound-mail ceiling
|--------------------------------------------------------------------------
*/

it('caps invitations per company rather than per user', function () {
    Notification::fake();

    // The ceiling is real requests through the real limiter; only its height
    // is lowered, so nothing about the mechanism is stubbed. CACHE_STORE is
    // `array` under phpunit.xml, so the counters start empty and do not
    // survive the test.
    config()->set('registration.invitations_per_day', 2);

    [$company, $owner] = tenant();
    $admin = User::factory()->for($company)->state(['role' => User::ROLE_ADMIN])->create();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'first@example.test', 'role' => 'member'])
        ->assertStatus(201);

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'second@example.test', 'role' => 'member'])
        ->assertStatus(201);

    // A second admin does not get a fresh allowance: the abuse is a tenant
    // mailing the world, not one person doing it.
    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'third@example.test', 'role' => 'member'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');

    // The refusal is a refusal: no third mail left the building.
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
});

it('gives another company its own allowance', function () {
    Notification::fake();

    config()->set('registration.invitations_per_day', 1);

    [, $owner] = tenant();
    [, $otherOwner] = tenant();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'first@example.test', 'role' => 'member'])
        ->assertStatus(201);

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'blocked@example.test', 'role' => 'member'])
        ->assertStatus(429);

    $this->actingAs($otherOwner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'allowed@example.test', 'role' => 'member'])
        ->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| Audit and isolation
|--------------------------------------------------------------------------
*/

it('audits the acceptance under the accepting company', function () {
    [$invitation, $plain, $company] = invitationWithToken();

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(201);

    $entry = asTenant($company, fn () => AuditLog::query()
        ->where('action', AuditAction::TeamInvitationAccepted->value)
        ->first());

    expect($entry)->not->toBeNull()
        ->and((int) $entry->company_id)->toBe($company->id)
        ->and((int) $entry->user_id)->toBe(User::query()->where('email', 'invitee@example.test')->value('id'));
});

it('is public: neither endpoint asks for a token', function () {
    [, $plain] = invitationWithToken();

    // No Authorization header anywhere in this file, which is the point — but
    // stated once explicitly, because "public" is the property the whole flow
    // depends on and a stray `verified` on the group would take it away
    // silently for anyone who never tests signed out.
    $this->getJson('/api/v1/invitations/'.$plain)->assertOk();

    $this->postJson('/api/v1/invitations/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertStatus(201);
});

it('does not leak an invitation belonging to another tenant through a signed-in session', function () {
    // The token is the only key. Being signed in to company A must neither
    // help nor hinder reading an invitation issued by company B — the endpoint
    // ignores the session entirely.
    [, $ownerOfA] = tenant();
    [, $plain] = invitationWithToken();

    $this->actingAs($ownerOfA, 'sanctum')->getJson('/api/v1/invitations/'.$plain)->assertOk();
    $this->actingAs($ownerOfA, 'sanctum')->getJson('/api/v1/invitations/'.Str::random(48))->assertStatus(404);
});
