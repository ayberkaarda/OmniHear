<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit\AuditAction;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| GET /settings/team
|--------------------------------------------------------------------------
*/

it('lists the company team to any role', function (string $role) {
    [$company, $caller] = tenant($role);
    User::factory()->for($company)->count(2)->create();

    $response = actingAs($caller, 'sanctum')
        ->getJson('/api/v1/settings/team')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'company_id', 'name', 'email', 'role', 'email_verified_at', 'two_factor_enabled', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

    expect($response->json('meta.total'))->toBe(3);
})->with([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER]);

it('never lists another company team member', function () {
    [$companyA, $userA] = tenant();
    $companyB = Company::factory()->create();
    $stranger = User::factory()->for($companyB)->create();

    $ids = actingAs($userA, 'sanctum')->getJson('/api/v1/settings/team')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$userA->id])
        ->and($ids)->not->toContain($stranger->id);
});

/*
|--------------------------------------------------------------------------
| POST /settings/team/invitations
|--------------------------------------------------------------------------
*/

it('creates an invitation row rather than a user', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    $response = actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', [
            'email' => 'newcomer@acme.test',
            'role' => User::ROLE_MEMBER,
        ])
        ->assertCreated()
        ->assertJsonPath('invitation.email', 'newcomer@acme.test')
        ->assertJsonPath('invitation.role', 'member')
        ->assertJsonStructure(['invitation' => ['id', 'email', 'role', 'expires_at', 'accepted_at', 'created_at']]);

    expect($company->users()->where('email', 'newcomer@acme.test')->exists())->toBeFalse()
        ->and(asTenant($company, fn () => Invitation::query()->count()))->toBe(1)
        ->and($response->json('invitation'))->not->toHaveKey('token_hash')
        ->and($response->json('invitation'))->not->toHaveKey('token');
});

it('never returns or stores the plaintext invitation token', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    $body = actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'member'])
        ->assertCreated()
        ->getContent();

    $stored = asTenant($company, fn () => Invitation::query()->firstOrFail());

    // The column holds a sha256 hex digest, which is the shape of a hash and
    // not of the 48-character random string it was derived from.
    expect($stored->token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($body)->not->toContain($stored->token_hash);
});

it('refreshes the existing invitation instead of colliding on the unique key', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'member'])
        ->assertCreated();

    $first = asTenant($company, fn () => Invitation::query()->firstOrFail());

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'admin'])
        ->assertCreated()
        ->assertJsonPath('invitation.role', 'admin');

    $rows = asTenant($company, fn () => Invitation::query()->get());

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($first->id)
        ->and($rows->first()->token_hash)->not->toBe($first->token_hash);
});

it('refuses an invitation from a member', function () {
    [$company, $member] = tenant(User::ROLE_MEMBER);

    actingAs($member, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'member'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(asTenant($company, fn () => Invitation::query()->count()))->toBe(0);
});

it('refuses an admin inviting at owner level', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $admin = User::factory()->for($company)->admin()->create();

    actingAs($admin, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'owner'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(asTenant($company, fn () => Invitation::query()->count()))->toBe(0);
});

it('lets an owner invite an owner', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'owner'])
        ->assertCreated()
        ->assertJsonPath('invitation.role', 'owner');
});

it('refuses an unknown role as a validation error, not as a refusal of authority', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'superuser'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['role']]);
});

it('refuses inviting somebody who is already on the team', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $existing = User::factory()->for($company)->create();

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => $existing->email, 'role' => 'member'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['email']]);
});

it('does not leak that an address exists in another tenant', function () {
    [$companyA, $owner] = tenant(User::ROLE_OWNER);
    $stranger = User::factory()->for(Company::factory()->create())->create();

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => $stranger->email, 'role' => 'member'])
        ->assertCreated();
});

it('keeps an invitation inside its own tenant', function () {
    [$companyA, $ownerA] = tenant(User::ROLE_OWNER);
    $companyB = Company::factory()->create();

    actingAs($ownerA, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'member'])
        ->assertCreated();

    expect(asTenant($companyB, fn () => Invitation::query()->count()))->toBe(0)
        ->and(asTenant($companyA, fn () => Invitation::query()->count()))->toBe(1)
        ->and(asTenant($companyA, fn () => $companyA->invitations()->count()))->toBe(1)
        ->and(asTenant($companyA, fn () => Invitation::query()->firstOrFail()->inviter?->id))
        ->toBe($ownerA->id);
});

it('audits an invitation', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/settings/team/invitations', ['email' => 'a@acme.test', 'role' => 'member'])
        ->assertCreated();

    $actions = asTenant($company, fn () => AuditLog::query()->pluck('action')->all());

    expect($actions)->toContain(AuditAction::TeamInvited->value);
});

/*
|--------------------------------------------------------------------------
| PATCH /settings/team/{user}
|--------------------------------------------------------------------------
*/

it('lets an owner change a teammate role', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $member = User::factory()->for($company)->member()->create();

    actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/settings/team/{$member->id}", ['role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('user.role', 'admin');

    expect($member->fresh()->role)->toBe('admin');

    $actions = asTenant($company, fn () => AuditLog::query()->pluck('action')->all());
    expect($actions)->toContain(AuditAction::TeamRoleChanged->value);
});

it('refuses a role change from an admin', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $admin = User::factory()->for($company)->admin()->create();
    $member = User::factory()->for($company)->member()->create();

    actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/settings/team/{$member->id}", ['role' => 'admin'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($member->fresh()->role)->toBe('member');
});

it('refuses a role change from a member', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $member = User::factory()->for($company)->member()->create();

    actingAs($member, 'sanctum')
        ->patchJson("/api/v1/settings/team/{$owner->id}", ['role' => 'member'])
        ->assertStatus(403);

    expect($owner->fresh()->role)->toBe('owner');
});

it('refuses changing your own role', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    User::factory()->for($company)->owner()->create();

    actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/settings/team/{$owner->id}", ['role' => 'member'])
        ->assertStatus(403);

    expect($owner->fresh()->role)->toBe('owner');
});

/**
 * The company can never be left without an owner through this endpoint, and
 * the mechanism is worth stating because it is structural rather than a check:
 * only an owner may change a role and nobody may change their own, so a
 * demotable owner always has at least one other owner standing beside it. The
 * explicit last-owner refusal lives on DELETE, where an admin *can* address the
 * sole owner — see "refuses removing the last owner" below.
 */
it('cannot leave the company without an owner through a role change', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $second = User::factory()->for($company)->owner()->create();

    // Two owners: demoting one is allowed and one is left.
    actingAs($second, 'sanctum')
        ->patchJson("/api/v1/settings/team/{$owner->id}", ['role' => 'member'])
        ->assertOk();

    // The remaining owner is the only caller with the authority, and it is
    // itself the target, which is refused.
    actingAs($second, 'sanctum')
        ->patchJson("/api/v1/settings/team/{$second->id}", ['role' => 'member'])
        ->assertStatus(403);

    expect($company->users()->where('role', User::ROLE_OWNER)->count())->toBe(1)
        ->and($second->fresh()->role)->toBe('owner');
});

it('answers 404 for a role change on another tenant user', function () {
    [$companyA, $ownerA] = tenant(User::ROLE_OWNER);
    $stranger = User::factory()->for(Company::factory()->create())->member()->create();

    actingAs($ownerA, 'sanctum')
        ->patchJson("/api/v1/settings/team/{$stranger->id}", ['role' => 'admin'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect($stranger->fresh()->role)->toBe('member');
});

/*
|--------------------------------------------------------------------------
| DELETE /settings/team/{user}
|--------------------------------------------------------------------------
*/

it('lets an owner remove a teammate and revokes their tokens', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $member = User::factory()->for($company)->member()->create();
    $member->createToken('their-laptop');

    actingAs($owner, 'sanctum')
        ->deleteJson("/api/v1/settings/team/{$member->id}")
        ->assertNoContent();

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()
            ->where('tokenable_id', $member->id)
            ->where('tokenable_type', (new User)->getMorphClass())
            ->count())->toBe(0);

    $actions = asTenant($company, fn () => AuditLog::query()->pluck('action')->all());
    expect($actions)->toContain(AuditAction::TeamMemberRemoved->value);
});

it('lets an admin remove a teammate', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $admin = User::factory()->for($company)->admin()->create();
    $member = User::factory()->for($company)->member()->create();

    actingAs($admin, 'sanctum')
        ->deleteJson("/api/v1/settings/team/{$member->id}")
        ->assertNoContent();

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse();
});

it('refuses a removal from a member', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $member = User::factory()->for($company)->member()->create();
    $victim = User::factory()->for($company)->member()->create();

    actingAs($member, 'sanctum')
        ->deleteJson("/api/v1/settings/team/{$victim->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(User::query()->whereKey($victim->id)->exists())->toBeTrue();
});

it('refuses removing yourself', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    User::factory()->for($company)->owner()->create();

    actingAs($owner, 'sanctum')
        ->deleteJson("/api/v1/settings/team/{$owner->id}")
        ->assertStatus(403);

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

it('refuses removing the last owner', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $admin = User::factory()->for($company)->admin()->create();

    actingAs($admin, 'sanctum')
        ->deleteJson("/api/v1/settings/team/{$owner->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

it('answers 404 for a removal across tenants', function () {
    [$companyA, $ownerA] = tenant(User::ROLE_OWNER);
    $stranger = User::factory()->for(Company::factory()->create())->member()->create();

    actingAs($ownerA, 'sanctum')
        ->deleteJson("/api/v1/settings/team/{$stranger->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect(User::query()->whereKey($stranger->id)->exists())->toBeTrue();
});
