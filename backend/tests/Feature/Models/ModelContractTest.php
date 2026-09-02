<?php

use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonInterface;

it('computes the remaining quota', function (int $limit, int $used, int $remaining) {
    $company = Company::factory()->create(['quota_limit' => $limit, 'analyzed_feedback_count' => $used]);

    expect($company->quotaRemaining())->toBe($remaining);
})->with([
    [200, 0, 200],
    [200, 12, 188],
    [200, 200, 0],
    [10, 25, 0],
]);

it('wires the company relations', function () {
    $company = Company::factory()->create();
    User::factory()->for($company)->create();
    Subscription::factory()->for($company)->create();
    $integration = Integration::factory()->for($company)->create();
    Feedback::factory()->for($company)->for($integration)->create();
    AuditLog::factory()->for($company)->create();

    asTenant($company, function () use ($company) {
        expect($company->users()->count())->toBe(2) // the audit log factory creates one too
            ->and($company->subscriptions()->count())->toBe(1)
            ->and($company->integrations()->count())->toBe(1)
            ->and($company->feedbacks()->count())->toBe(1)
            ->and($company->auditLogs()->count())->toBe(1);
    });
});

it('wires the feedback relations', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create();
    $feedback = Feedback::factory()->for($company)->for($integration)->create();
    $analysis = AiAnalysis::factory()->for($company)->for($feedback)->create();

    asTenant($company, function () use ($feedback, $integration, $analysis, $company) {
        expect($feedback->integration->id)->toBe($integration->id)
            ->and($feedback->analysis->id)->toBe($analysis->id)
            ->and($feedback->company->id)->toBe($company->id)
            ->and($analysis->feedback->id)->toBe($feedback->id)
            ->and($integration->feedbacks()->count())->toBe(1);
    });
});

it('casts the json columns back into arrays', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create(['settings' => ['locale' => 'tr']]);
    $feedback = Feedback::factory()->for($company)->for($integration)->create(['raw_payload' => ['a' => 1]]);
    $analysis = AiAnalysis::factory()->for($company)->for($feedback)->create(['keywords' => ['slow', 'crash']]);

    $reloaded = asTenant($company, fn () => [
        Integration::query()->findOrFail($integration->id),
        Feedback::query()->findOrFail($feedback->id),
        AiAnalysis::query()->findOrFail($analysis->id),
    ]);

    expect($reloaded[0]->settings)->toBe(['locale' => 'tr'])
        ->and($reloaded[1]->raw_payload)->toBe(['a' => 1])
        ->and($reloaded[2]->keywords)->toBe(['slow', 'crash'])
        ->and($reloaded[1]->published_at)->toBeInstanceOf(CarbonInterface::class);
});

it('keeps audit log rows without an updated_at attribute', function () {
    $company = Company::factory()->create();
    $log = AuditLog::factory()->for($company)->create(['action' => 'auth.login']);

    expect($log->getAttributes())->not->toHaveKey('updated_at')
        ->and($log->created_at)->not->toBeNull()
        ->and($log->user->getAttribute('company_id'))->toBe($company->id);
});

it('casts subscription periods to dates', function () {
    $company = Company::factory()->create();
    $subscription = Subscription::factory()->for($company)->create();

    expect($subscription->current_period_start)->toBeInstanceOf(CarbonInterface::class)
        ->and($subscription->current_period_end)->toBeInstanceOf(CarbonInterface::class)
        ->and($subscription->canceled_at)->toBeNull();
});

it('stores a webhook event outside any tenant', function () {
    $event = WebhookEvent::factory()->create(['payload' => ['type' => 'checkout.session.completed']]);

    expect($event->payload)->toBe(['type' => 'checkout.session.completed'])
        ->and($event->processed_at)->toBeNull()
        ->and(WebhookEvent::query()->count())->toBe(1);
});

it('exposes the platform and status vocabularies the connectors rely on', function () {
    expect(Integration::PLATFORMS)->toBe([
        'appstore', 'googleplay', 'zendesk', 'trustpilot', 'email', 'social', 'fixture',
    ])
        ->and(Integration::STATUSES)->toBe(['active', 'error', 'paused'])
        ->and(Feedback::STATUSES)->toBe(['pending_analysis', 'analyzing', 'analyzed', 'failed'])
        ->and(AiAnalysis::SENTIMENT_LABELS)->toBe(['positive', 'neutral', 'negative'])
        ->and(AiAnalysis::CATEGORIES)->toBe(['complaint', 'praise', 'bug', 'feature_request'])
        ->and(User::ROLES)->toBe(['owner', 'admin', 'member']);
});

it('defaults a new feedback to pending analysis', function () {
    $company = Company::factory()->create();
    $integration = Integration::factory()->for($company)->create();

    $feedback = asTenant($company, fn () => Feedback::create([
        'integration_id' => $integration->id,
        'external_id' => 'pending-1',
        'body' => 'Waiting for the analyzer.',
        'raw_payload' => [],
    ]));

    $reloaded = asTenant($company, fn () => Feedback::query()->findOrFail($feedback->id));

    expect($reloaded->analysis_status)->toBe('pending_analysis');
});
