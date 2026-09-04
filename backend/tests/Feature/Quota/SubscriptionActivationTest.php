<?php

use App\Events\SubscriptionActivated;
use App\Jobs\AnalyzeFeedbackJob;
use App\Jobs\RequeuePendingAnalysisJob;
use App\Listeners\ActivateSubscriptionPlan;
use App\Models\Feedback;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * The payments -> analysis seam and the post-upgrade sweep
 * (spec 7.5, docs/contracts/wave2-seams.md section 2).
 *
 * Driven by dispatching SubscriptionActivated directly. Nothing here calls into
 * the payments track's code, and nothing there calls into this one.
 */
it('registers the listener by auto-discovery', function () {
    $registered = array_map(
        fn ($listener) => is_array($listener) ? (string) $listener[0] : (string) $listener,
        Event::getRawListeners()[SubscriptionActivated::class] ?? [],
    );

    expect(implode('|', $registered))->toContain(ActivateSubscriptionPlan::class);
});

it('raises the quota limit from config and re-queues the backlog', function () {
    Queue::fake();
    config(['quota.plans.pro.quota_limit' => 2000]);

    [$company] = tenant();
    $company->forceFill(['plan' => 'free', 'quota_limit' => 200])->save();

    SubscriptionActivated::dispatch($company->id, 'stripe', 'pro');

    $company->refresh();

    // The number comes from config/quota.php and from nowhere else.
    expect($company->plan)->toBe('pro')
        ->and((int) $company->quota_limit)->toBe(2000);

    Queue::assertPushed(
        RequeuePendingAnalysisJob::class,
        fn (RequeuePendingAnalysisJob $job) => $job->companyId === $company->id
    );
});

it('drops the cached KPI payload so the new quota limit is served immediately', function () {
    // The dashboard's quota.limit (OverviewController::compute) is cached per
    // tenant (App\Support\Overview\KpiCache). Without a forget at the write in
    // ActivateSubscriptionPlan, a request landing inside the TTL right after
    // an upgrade would keep answering the pre-upgrade limit here while
    // QuotaStore - a different source, backing the paywall/meter - already
    // reports the new one: two different limits on one screen, at the moment
    // the customer just paid.
    Queue::fake();
    config(['overview.cache.ttl' => 60]);
    config(['quota.plans.pro.quota_limit' => 2000]);

    [$company, $user] = tenant();
    $company->forceFill(['plan' => 'free', 'quota_limit' => 200])->save();

    // Prime the cache with the pre-upgrade limit.
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis')
        ->assertOk()
        ->assertJsonPath('quota.limit', 200);

    SubscriptionActivated::dispatch($company->id, 'stripe', 'pro');

    // Without the forget, this would still answer 200 - the stale entry
    // primed above - even though companies.quota_limit is already 2000.
    $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/overview/kpis')
        ->assertOk()
        ->assertJsonPath('quota.limit', 2000);
});

it('leaves the existing limit alone when the plan has no configured quota', function () {
    Queue::fake();
    Log::spy();
    config(['quota.plans.pro.quota_limit' => null]);

    [$company] = tenant();
    $company->forceFill(['plan' => 'free', 'quota_limit' => 200])->save();

    SubscriptionActivated::dispatch($company->id, 'iyzico', 'pro');

    // config/quota.php still carries null for `pro` - the payments phase owns
    // that number. Writing a guess into a customer's row would be worse than
    // doing nothing and saying so.
    expect((int) $company->fresh()->quota_limit)->toBe(200)
        ->and($company->fresh()->plan)->toBe('pro');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message) => $message === 'quota.plan_limit_not_configured'
    );

    Queue::assertPushed(RequeuePendingAnalysisJob::class);
});

it('survives an activation for a company that no longer exists', function () {
    Queue::fake();

    SubscriptionActivated::dispatch(9_999_999, 'stripe', 'pro');

    Queue::assertNothingPushed();
});

it('re-queues every pending feedback and nothing else', function () {
    Queue::fake();

    [$company] = tenant();
    $pending = Feedback::factory()->count(3)->for($company)->create();
    $analyzed = Feedback::factory()->for($company)->analyzed()->create();
    $failed = Feedback::factory()->for($company)->create(['analysis_status' => Feedback::STATUS_FAILED]);

    (new RequeuePendingAnalysisJob($company->id))->handle(app(TenantContext::class));

    Queue::assertPushed(AnalyzeFeedbackJob::class, 3);

    foreach ($pending as $feedback) {
        Queue::assertPushed(
            AnalyzeFeedbackJob::class,
            fn (AnalyzeFeedbackJob $job) => $job->feedbackId === $feedback->id
        );
    }

    foreach ([$analyzed, $failed] as $feedback) {
        Queue::assertNotPushed(
            AnalyzeFeedbackJob::class,
            fn (AnalyzeFeedbackJob $job) => $job->feedbackId === $feedback->id
        );
    }
});

it('never re-queues another tenant backlog', function () {
    Queue::fake();

    [$company] = tenant();
    [$other] = tenant();
    Feedback::factory()->count(2)->for($company)->create();
    Feedback::factory()->count(4)->for($other)->create();

    (new RequeuePendingAnalysisJob($company->id))->handle(app(TenantContext::class));

    // Invariant I1 inside a queue worker: the sweep is scoped by the tenant the
    // job carries, not by whatever context the previous job left behind.
    Queue::assertPushed(AnalyzeFeedbackJob::class, 2);
    Queue::assertNotPushed(
        AnalyzeFeedbackJob::class,
        fn (AnalyzeFeedbackJob $job) => $job->companyId === $other->id
    );
});

it('does nothing when the backlog is empty', function () {
    Queue::fake();
    [$company] = tenant();

    (new RequeuePendingAnalysisJob($company->id))->handle(app(TenantContext::class));

    Queue::assertNothingPushed();
});
