<?php

use App\Http\Middleware\EnforceQuota;
use App\Http\Middleware\SetTenantContext;
use App\Jobs\AnalyzeFeedbackJob;
use App\Models\Feedback;
use App\Support\Quota\QuotaCounter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiServiceFake;

/**
 * Spec 7.4 - what happens when the quota runs out.
 *
 * Two halves: the job pauses and the feedback accumulates, and the HTTP surface
 * answers 402 QUOTA_EXCEEDED. Neither half deletes anything - the backlog is
 * what the post-upgrade sweep re-queues (spec 7.5), so losing it would make the
 * upgrade worthless.
 */
beforeEach(function () {
    testApiRoute('post', '_probe/consume', fn () => response()->json(['ok' => true]), [
        'auth:sanctum',
        SetTenantContext::class,
        'quota.header',
        'quota',
    ]);
});

it('answers 402 with the catalogue envelope once the quota is exhausted', function () {
    [$company, $user] = tenant();
    $company->forceFill(['quota_limit' => 5, 'analyzed_feedback_count' => 5])->save();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/_probe/consume')
        ->assertStatus(402)
        ->assertExactJson([
            'code' => 'QUOTA_EXCEEDED',
            'message' => 'Your analysis quota is exhausted. Upgrade your plan to continue.',
        ])
        ->assertHeader('X-Quota-Remaining', '0');
});

it('lets the request through while quota remains', function () {
    [$company, $user] = tenant();
    $company->forceFill(['quota_limit' => 5, 'analyzed_feedback_count' => 4])->save();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/_probe/consume')
        ->assertOk()
        ->assertHeader('X-Quota-Remaining', '1');
});

it('keeps X-Quota-Remaining accurate and floored at zero on read endpoints', function () {
    [$company, $user] = tenant();
    $company->forceFill(['quota_limit' => 10, 'analyzed_feedback_count' => 3])->save();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks')
        ->assertOk()
        ->assertHeader('X-Quota-Remaining', '7');

    // Overshoot is possible in principle (a limit lowered under a used count);
    // the header must never go negative, because the SPA renders it directly.
    $company->forceFill(['quota_limit' => 2, 'analyzed_feedback_count' => 9])->save();

    // ->fresh() matters here and only here. actingAs pins one PHP instance of
    // the user for the whole test, and the first request above already lazy
    // loaded ->company onto it, so a second request would read the previous
    // limit and report 7 again. Production never sees this: Sanctum resolves
    // the user from the token on every request, so the relation is always new.
    $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/overview/kpis')
        ->assertOk()
        ->assertHeader('X-Quota-Remaining', '0');
});

it('still serves the inbox and the KPIs when the quota is exhausted', function () {
    [$company, $user] = tenant();
    $company->forceFill(['quota_limit' => 1, 'analyzed_feedback_count' => 1])->save();
    Feedback::factory()->for($company)->create();

    // The paywall is rendered over these screens; gating them behind the same
    // 402 would leave the user with nowhere to see it.
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks')->assertOk();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis')->assertOk();
});

it('parks the feedback instead of failing when the analyzer would be next', function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }

    [$company] = tenant();
    $company->forceFill(['quota_limit' => 1, 'analyzed_feedback_count' => 1])->save();
    $feedback = Feedback::factory()->for($company)->create(['analysis_status' => Feedback::STATUS_ANALYZING]);
    AiServiceFake::fakeSuccess();

    (new AnalyzeFeedbackJob($company->id, $feedback->id))->handle(app(TenantContext::class));

    // Green job, no analyzer call, no retry, no dead letter: running out of
    // quota is a normal outcome, not an error.
    Http::assertNothingSent();
    expect($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_PENDING)
        ->and((int) $company->fresh()->analyzed_feedback_count)->toBe(1);
});

it('accumulates the backlog in pending_analysis and deletes nothing', function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }

    [$company] = tenant();
    $company->forceFill(['quota_limit' => 2, 'analyzed_feedback_count' => 0])->save();
    $feedbacks = Feedback::factory()->count(5)->for($company)->create();
    AiServiceFake::fakeSuccess();

    foreach ($feedbacks as $feedback) {
        (new AnalyzeFeedbackJob($company->id, $feedback->id))->handle(app(TenantContext::class));
    }

    $statuses = asTenant($company, fn () => Feedback::query()
        ->selectRaw('analysis_status, count(*) as aggregate')
        ->groupBy('analysis_status')
        ->pluck('aggregate', 'analysis_status'));

    expect((int) $company->fresh()->analyzed_feedback_count)->toBe(2)
        ->and((int) $statuses[Feedback::STATUS_ANALYZED])->toBe(2)
        ->and((int) $statuses[Feedback::STATUS_PENDING])->toBe(3)
        // Spec 7.4: the surplus accumulates, it is never dropped.
        ->and(asTenant($company, fn () => Feedback::query()->count()))->toBe(5);
});

it('never lets the counter exceed the limit', function () {
    [$company] = tenant();
    $company->forceFill(['quota_limit' => 3, 'analyzed_feedback_count' => 0])->save();

    $counter = new QuotaCounter;
    $granted = 0;

    foreach (range(1, 10) as $ignored) {
        if ($counter->reserve($company->id) !== null) {
            $granted++;
        }
    }

    expect($granted)->toBe(3)
        ->and((int) $company->fresh()->analyzed_feedback_count)->toBe(3);
});

it('gives a reserved unit back exactly once and never goes negative', function () {
    [$company] = tenant();
    $company->forceFill(['quota_limit' => 3, 'analyzed_feedback_count' => 0])->save();

    $counter = new QuotaCounter;
    $counter->reserve($company->id);
    $counter->release($company->id);
    $counter->release($company->id);

    expect((int) $company->fresh()->analyzed_feedback_count)->toBe(0);
});

it('reports the reservation against the current limit', function () {
    [$company] = tenant();
    $company->forceFill(['quota_limit' => 4, 'analyzed_feedback_count' => 1])->save();

    $snapshot = (new QuotaCounter)->reserve($company->id);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->used)->toBe(2)
        ->and($snapshot->limit)->toBe(4)
        ->and($snapshot->remaining())->toBe(2);
});

it('is registered under the quota middleware alias', function () {
    // bootstrap/app.php is this track's file; the alias is what lets the
    // ingestion trigger - which does spend quota - opt into the same 402.
    expect(app('router')->getMiddleware())
        ->toHaveKey('quota')
        ->and(app('router')->getMiddleware()['quota'])->toBe(EnforceQuota::class);
});
