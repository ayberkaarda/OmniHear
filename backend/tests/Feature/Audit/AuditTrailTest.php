<?php

use App\Events\SubscriptionActivated;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Integration;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Payments\PaymentTestKit;

/*
|--------------------------------------------------------------------------
| Spec 5 / spec 8 — audit_logs actually gets written
|--------------------------------------------------------------------------
|
| The table, the model and the factory shipped in F2 with no writer, which
| reads as coverage while providing none. These tests assert the rows, not the
| plumbing: what a compliance reviewer would query.
|
*/

/**
 * Every audit row of a company, oldest first, without needing a tenant context.
 *
 * @return Collection<int, AuditLog>
 */
function auditRows(Company $company)
{
    return AuditLog::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->orderBy('id')
        ->get();
}

function auditActions(Company $company): array
{
    return auditRows($company)->pluck('action')->all();
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

it('records a successful login with the actor, the tenant and the ip', function () {
    [$company, $user] = tenant();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $row = auditRows($company)->firstWhere('action', AuditAction::LoginSucceeded->value);

    expect($row)->not->toBeNull()
        ->and((int) $row->company_id)->toBe($company->id)
        ->and((int) $row->user_id)->toBe($user->id)
        ->and($row->ip)->not->toBeNull()
        ->and($row->created_at)->not->toBeNull();
});

it('records a failed login against the account it was aimed at', function () {
    [$company, $user] = tenant();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password-entirely',
    ])->assertStatus(401);

    expect(auditActions($company))->toBe([AuditAction::LoginFailed->value]);
});

it('files an unknown-address login attempt in the log, not in a tenant table', function () {
    Log::spy();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@acme-analytics.com',
        'password' => 'whatever-it-is-here',
    ])->assertStatus(401);

    expect(AuditLog::withoutGlobalScopes()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'auth.login_failed'
            && $context['reason'] === 'unknown_account'
    )->once();
});

it('never writes the attempted address into the log context', function () {
    Log::spy();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@acme-analytics.com',
        'password' => 'whatever-it-is-here',
    ])->assertStatus(401);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        return ! str_contains(json_encode($context), 'nobody@acme-analytics.com');
    })->once();
});

it('records a logout as a token revocation', function () {
    [$company, $user] = tenant();
    $token = $user->createToken('web')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    $row = auditRows($company)->firstWhere('action', AuditAction::LoggedOut->value);

    expect($row)->not->toBeNull()
        ->and((int) $row->user_id)->toBe($user->id)
        ->and($row->subject_type)->toBe(PersonalAccessToken::class)
        ->and($row->subject_id)->not->toBeNull();
});

it('records an explicit device revocation with the token as subject', function () {
    [$company, $user] = tenant();
    $device = $user->createToken('iPhone')->accessToken;

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/auth/tokens/{$device->id}")
        ->assertNoContent();

    $row = auditRows($company)->firstWhere('action', AuditAction::TokenRevoked->value);

    expect($row)->not->toBeNull()
        ->and((int) $row->subject_id)->toBe($device->id)
        ->and($row->subject_type)->toBe(PersonalAccessToken::class);
});

/*
|--------------------------------------------------------------------------
| Integrations
|--------------------------------------------------------------------------
*/

it('records the integration lifecycle from the api', function () {
    [$company, $user] = tenant();

    $created = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/integrations', [
            'platform' => 'fixture',
            'settings' => ['locale' => 'en'],
            'credentials' => ['api_key' => 'k'],
        ])->assertCreated()->json('integration.id');

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/integrations/{$created}", ['status' => 'paused'])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/v1/integrations/{$created}")
        ->assertNoContent();

    expect(auditActions($company))->toBe([
        AuditAction::IntegrationCreated->value,
        AuditAction::IntegrationUpdated->value,
        AuditAction::IntegrationDeleted->value,
    ]);

    $row = auditRows($company)->first();

    expect($row->subject_type)->toBe(Integration::class)
        ->and((int) $row->subject_id)->toBe((int) $created)
        ->and((int) $row->user_id)->toBe($user->id);
});

it('records a manual sync request against the human who asked', function () {
    Queue::fake();
    [$company, $user] = tenant();
    $integration = asTenant($company, fn () => Integration::factory()->for($company)->create());

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/integrations/{$integration->id}/sync")
        ->assertStatus(202);

    $row = auditRows($company)->firstWhere('action', AuditAction::IntegrationSyncRequested->value);

    expect($row)->not->toBeNull()
        ->and((int) $row->user_id)->toBe($user->id)
        ->and((int) $row->subject_id)->toBe($integration->id);
});

it('does not audit the background writes the ingestion runner makes', function () {
    [$company, $user] = tenant();

    $integration = asTenant($company, function () use ($company) {
        $model = Integration::factory()->for($company)->errored()->create();
        $model->forceFill([
            'sync_cursor' => 'cursor-2',
            'last_synced_at' => now(),
            'sync_error' => null,
            'status' => 'active',
        ])->save();

        return $model;
    });

    expect($integration->status)->toBe('active')
        ->and(auditActions($company))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
*/

it('records a started checkout', function () {
    PaymentTestKit::configure();

    Http::fake([
        PaymentTestKit::STRIPE_API_BASE.'/*' => Http::response(
            PaymentTestKit::fixture('stripe', 'checkout-session-created-response'),
        ),
    ]);

    [$company, $user] = tenant();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/billing/checkout', ['provider' => 'stripe', 'plan' => 'pro'])
        ->assertOk();

    $row = auditRows($company)->firstWhere('action', AuditAction::CheckoutStarted->value);

    expect($row)->not->toBeNull()->and((int) $row->user_id)->toBe($user->id);
});

it('records a subscription activation with no actor, because a webhook has none', function () {
    Queue::fake();
    $company = Company::factory()->create();

    SubscriptionActivated::dispatch($company->id, 'stripe', 'pro');

    $row = auditRows($company)->firstWhere('action', AuditAction::SubscriptionActivated->value);

    expect($row)->not->toBeNull()
        ->and((int) $row->company_id)->toBe($company->id)
        ->and($row->user_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The writer itself
|--------------------------------------------------------------------------
*/

it('returns null instead of inventing a tenant when there is none', function () {
    $logger = app(AuditLogger::class);

    expect($logger->record(AuditAction::LoginFailed))->toBeNull()
        ->and(AuditLog::withoutGlobalScopes()->count())->toBe(0);
});

it('never names an actor from another tenant in a company trail', function () {
    [$companyA] = tenant();
    [$companyB, $userB] = tenant();

    $row = app(AuditLogger::class)->record(
        AuditAction::LoginSucceeded,
        actor: $userB,
        companyId: $companyA->id,
    );

    expect((int) $row->company_id)->toBe($companyA->id)
        ->and($row->user_id)->toBeNull();
});

it('falls back to the tenant context when no actor is given', function () {
    [$company] = tenant();

    $row = app(TenantContext::class)->runFor(
        $company->id,
        fn () => app(AuditLogger::class)->record(AuditAction::SubscriptionActivated),
    );

    expect((int) $row->company_id)->toBe($company->id);
});

it('keeps audit rows immutable — created_at only, no updated_at', function () {
    [$company, $user] = tenant();

    $row = app(AuditLogger::class)->record(AuditAction::LoginSucceeded, actor: $user);

    expect($row->getAttributes())->not->toHaveKey('updated_at')
        ->and(AuditLog::UPDATED_AT)->toBeNull();
});

it('scopes audit reads to the tenant', function () {
    [$companyA, $userA] = tenant();
    [$companyB, $userB] = tenant();

    app(AuditLogger::class)->record(AuditAction::LoginSucceeded, actor: $userA);
    app(AuditLogger::class)->record(AuditAction::LoginSucceeded, actor: $userB);

    $visible = asTenant($companyA, fn () => AuditLog::query()->pluck('company_id')->all());

    expect(array_map('intval', $visible))->toBe([$companyA->id]);
});

it('covers the whole action vocabulary with distinct values', function () {
    $values = array_map(fn (AuditAction $case): string => $case->value, AuditAction::cases());

    expect($values)->toBe(array_unique($values))
        ->and(collect($values)->every(fn (string $v): bool => strlen($v) <= 100))->toBeTrue();
});
