<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\QuotaWarningNotification;
use App\Support\Audit\AuditAction;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| GET / PATCH /settings/notifications
|--------------------------------------------------------------------------
*/

it('returns both channels on by default', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->getJson('/api/v1/settings/notifications')
        ->assertOk()
        ->assertExactJson([
            'preferences' => ['quota_warning' => ['mail' => true, 'database' => true]],
        ]);
});

it('stores a partial update and answers with the whole document', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/settings/notifications', ['quota_warning' => ['mail' => false]])
        ->assertOk()
        ->assertExactJson([
            'preferences' => ['quota_warning' => ['mail' => false, 'database' => true]],
        ]);

    expect($company->fresh()->notification_preferences)
        ->toBe(['quota_warning' => ['mail' => false, 'database' => true]]);
});

it('does not reset an untouched channel on the next patch', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/settings/notifications', ['quota_warning' => ['database' => false]])
        ->assertOk();

    actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/settings/notifications', ['quota_warning' => ['mail' => false]])
        ->assertOk()
        ->assertJsonPath('preferences.quota_warning.database', false)
        ->assertJsonPath('preferences.quota_warning.mail', false);
});

it('drops an unknown event and an unknown channel', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/settings/notifications', [
            'quota_warning' => ['mail' => false, 'carrier_pigeon' => true],
            'invoice_ready' => ['mail' => true],
        ])
        ->assertOk()
        ->assertExactJson([
            'preferences' => ['quota_warning' => ['mail' => false, 'database' => true]],
        ]);

    expect(array_keys($company->fresh()->notification_preferences))->toBe(['quota_warning']);
});

it('refuses a non boolean channel value', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/settings/notifications', ['quota_warning' => ['mail' => 'maybe']])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('lets any role read the preferences but only owner and admin write them', function () {
    [$company, $member] = tenant(User::ROLE_MEMBER);

    actingAs($member, 'sanctum')->getJson('/api/v1/settings/notifications')->assertOk();

    actingAs($member, 'sanctum')
        ->patchJson('/api/v1/settings/notifications', ['quota_warning' => ['mail' => false]])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($company->fresh()->notification_preferences)->toBeNull();
});

it('audits a preference change', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->patchJson('/api/v1/settings/notifications', ['quota_warning' => ['mail' => false]])
        ->assertOk();

    $actions = asTenant($company, fn () => AuditLog::query()->pluck('action')->all());

    expect($actions)->toContain(AuditAction::NotificationPreferencesUpdated->value);
});

it('keeps preferences inside their own tenant', function () {
    [$companyA, $ownerA] = tenant(User::ROLE_OWNER);
    $companyB = Company::factory()->create();

    actingAs($ownerA, 'sanctum')
        ->patchJson('/api/v1/settings/notifications', ['quota_warning' => ['mail' => false]])
        ->assertOk();

    expect($companyB->fresh()->notification_preferences)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| via() honours the preference (spec 7.3)
|--------------------------------------------------------------------------
*/

it('sends the quota warning on both channels by default', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    $channels = QuotaWarningNotification::forCompany($company, 8, 10)->via($owner);

    expect($channels)->toBe(['mail', 'database']);
});

it('drops a channel the company turned off', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $company->forceFill([
        'notification_preferences' => ['quota_warning' => ['mail' => false, 'database' => true]],
    ])->save();

    $channels = QuotaWarningNotification::forCompany($company, 8, 10)->via($owner->fresh());

    expect($channels)->toBe(['database']);
});

it('sends nothing when every channel is off', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $company->forceFill([
        'notification_preferences' => ['quota_warning' => ['mail' => false, 'database' => false]],
    ])->save();

    expect(QuotaWarningNotification::forCompany($company, 8, 10)->via($owner->fresh()))->toBe([]);
});

it('falls back to the defaults for a notifiable that is not a user', function () {
    expect(NotificationPreferences::forCompany(null)->channelsFor(NotificationPreferences::QUOTA_WARNING))
        ->toBe(['mail', 'database']);
});

it('writes a row the inbox can serve when the database channel fires', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    Notification::sendNow($owner, QuotaWarningNotification::forCompany($company, 8, 10));

    $row = $owner->notifications()->firstOrFail();

    expect($row->type)->toBe(QuotaWarningNotification::class)
        ->and($row->data)->toBe(['used' => 8, 'limit' => 10, 'company' => $company->name])
        ->and($row->read_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| GET /notifications and POST /notifications/{id}/read
|--------------------------------------------------------------------------
*/

it('serves the caller inbox newest first', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    Notification::sendNow($owner, QuotaWarningNotification::forCompany($company, 8, 10));
    $first = $owner->notifications()->firstOrFail();
    $first->forceFill(['created_at' => now()->subDay()])->save();

    Notification::sendNow($owner, QuotaWarningNotification::forCompany($company, 9, 10));

    $response = actingAs($owner, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'type', 'data', 'read_at', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

    expect($response->json('data.0.data.used'))->toBe(9)
        ->and($response->json('data.1.data.used'))->toBe(8)
        ->and($response->json('data.0.read_at'))->toBeNull();
});

it('never serves a teammate notification', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $teammate = User::factory()->for($company)->create();

    Notification::sendNow($teammate, QuotaWarningNotification::forCompany($company, 8, 10));

    actingAs($owner, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('marks a notification read and is idempotent', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    Notification::sendNow($owner, QuotaWarningNotification::forCompany($company, 8, 10));
    $id = $owner->notifications()->firstOrFail()->id;

    actingAs($owner, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertNoContent();

    $readAt = $owner->notifications()->firstOrFail()->read_at;

    expect($readAt)->not->toBeNull();

    actingAs($owner, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertNoContent();

    expect($owner->notifications()->firstOrFail()->read_at->toIso8601String())
        ->toBe($readAt->toIso8601String());

    $actions = asTenant($company, fn () => AuditLog::query()->pluck('action')->all());
    expect($actions)->toContain(AuditAction::NotificationRead->value);
});

it('answers 404 when marking a teammate notification read', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);
    $teammate = User::factory()->for($company)->create();

    Notification::sendNow($teammate, QuotaWarningNotification::forCompany($company, 8, 10));
    $id = $teammate->notifications()->firstOrFail()->id;

    actingAs($owner, 'sanctum')
        ->postJson("/api/v1/notifications/{$id}/read")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect($teammate->notifications()->firstOrFail()->read_at)->toBeNull();
});

it('answers 404 for a notification id that is not a uuid', function () {
    [$company, $owner] = tenant(User::ROLE_OWNER);

    actingAs($owner, 'sanctum')
        ->postJson('/api/v1/notifications/not-a-uuid/read')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('answers 404 for another tenant notification', function () {
    [$companyA, $ownerA] = tenant(User::ROLE_OWNER);
    $companyB = Company::factory()->create();
    $stranger = User::factory()->for($companyB)->owner()->create();

    Notification::sendNow($stranger, QuotaWarningNotification::forCompany($companyB, 8, 10));
    $id = $stranger->notifications()->firstOrFail()->id;

    actingAs($ownerA, 'sanctum')
        ->postJson("/api/v1/notifications/{$id}/read")
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});
