<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| Spec 8 — right to erasure (KVKK/GDPR)
|--------------------------------------------------------------------------
*/

/**
 * A tenant with one integration, one feedback row and two device tokens.
 *
 * @return array{0: Company, 1: User}
 */
function erasableTenant(string $role = User::ROLE_OWNER): array
{
    [$company, $user] = tenant($role);

    asTenant($company, function () use ($company): void {
        $integration = Integration::factory()->for($company)->create();
        Feedback::factory()->for($company)->for($integration)->create();
    });

    $user->createToken('web');
    $user->createToken('iPhone');

    return [$company, $user];
}

it('erases the company and everything cascading from it', function () {
    [$company, $user] = erasableTenant();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/account')
        ->assertStatus(202)
        ->assertJsonStructure(['message']);

    expect(Company::query()->whereKey($company->id)->exists())->toBeFalse()
        ->and(User::query()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(Integration::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(Feedback::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(AuditLog::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse();
});

it('revokes every device token of the tenant', function () {
    [$company, $user] = erasableTenant();
    $teammate = User::factory()->for($company)->create();
    $teammate->createToken('their-laptop');

    expect(PersonalAccessToken::query()->count())->toBe(3);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/account')->assertStatus(202);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('writes the audit entry while the company still exists', function () {
    [$company, $user] = erasableTenant();

    $observed = [];

    AuditLog::created(function (AuditLog $log) use (&$observed, $company): void {
        $observed[] = [
            'action' => $log->action,
            'user_id' => $log->user_id,
            'company_id' => $log->company_id,
            'subject_type' => $log->subject_type,
            'ip' => $log->ip,
            'company_alive' => Company::query()->whereKey($company->id)->exists(),
        ];
    });

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/account')->assertStatus(202);

    $erasure = collect($observed)->firstWhere('action', 'account.erased');

    expect($erasure)->not->toBeNull()
        ->and($erasure['company_alive'])->toBeTrue()
        ->and($erasure['company_id'])->toBe($company->id)
        ->and($erasure['user_id'])->toBe($user->id)
        ->and($erasure['subject_type'])->toBe(Company::class)
        ->and($erasure['ip'])->not->toBeNull();
});

it('leaves a durable structured record that outlives the tenant', function () {
    [$company, $user] = erasableTenant();

    Log::spy();

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/account')->assertStatus(202);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($company, $user): bool {
        return $message === 'account.erased'
            && $context === ['company_id' => $company->id, 'user_id' => $user->id];
    })->once();
});

it('touches no other tenant', function () {
    [$companyA, $userA] = erasableTenant();
    [$companyB, $userB] = erasableTenant();

    $this->actingAs($userA, 'sanctum')->deleteJson('/api/v1/account')->assertStatus(202);

    expect(Company::query()->whereKey($companyB->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($userB->id)->exists())->toBeTrue()
        ->and(Integration::withoutGlobalScopes()->where('company_id', $companyB->id)->count())->toBe(1)
        ->and(Feedback::withoutGlobalScopes()->where('company_id', $companyB->id)->count())->toBe(1)
        ->and(PersonalAccessToken::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('refuses every role below owner', function (string $role) {
    [$company, $user] = erasableTenant($role);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/account')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue();
})->with([User::ROLE_ADMIN, User::ROLE_MEMBER]);

it('requires authentication', function () {
    $this->deleteJson('/api/v1/account')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('takes no company id from the caller, so there is no cross tenant request to make', function () {
    [$companyA, $userA] = erasableTenant();
    [$companyB, $userB] = erasableTenant();

    // Whatever the body says, the endpoint reads the company off the token.
    $this->actingAs($userA, 'sanctum')
        ->deleteJson('/api/v1/account', ['company_id' => $companyB->id, 'id' => $companyB->id])
        ->assertStatus(202);

    expect(Company::query()->whereKey($companyA->id)->exists())->toBeFalse()
        ->and(Company::query()->whereKey($companyB->id)->exists())->toBeTrue();
});
