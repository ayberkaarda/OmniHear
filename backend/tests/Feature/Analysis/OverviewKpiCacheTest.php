<?php

use App\Events\FeedbackAnalyzed;
use App\Events\FeedbackIngested;
use App\Models\AiAnalysis;
use App\Models\Feedback;
use App\Support\Overview\KpiCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * The KPI cache (spec 2: Redis, KPI aggregation) and the invariant it puts at
 * risk.
 *
 * A cache is where a tenant leak hides. The second tenant's request never
 * reaches a query, so it never meets CompanyScope: it is answered from a
 * shared store by a string. If that string forgot the company id, company A's
 * dashboard would be served to company B and every single-tenant test in the
 * suite would still be green. Hence the first test here, and hence its size
 * relative to the rest.
 *
 * Note on the fixtures: Feedback::factory() writes rows directly and fires no
 * FeedbackIngested — which is what makes "the numbers changed but the cache
 * did not" observable at all. Where an event *is* wanted it is dispatched
 * explicitly, with Queue::fake() in front of it because QueueFeedbackAnalysis
 * would otherwise run an AnalyzeFeedbackJob against a real analyzer on the
 * sync queue.
 */
beforeEach(function () {
    Queue::fake();
    config(['overview.cache.ttl' => 60]);
});

function kpiTotal($test, $user): int
{
    return (int) $test->actingAs($user->fresh(), 'sanctum')
        ->getJson('/api/v1/overview/kpis')
        ->assertOk()
        ->json('total_feedbacks');
}

it('never serves one company cached KPIs to another', function () {
    [$companyA, $userA] = tenant();
    [$companyB, $userB] = tenant();

    Feedback::factory()->count(3)->for($companyA)->create();
    Feedback::factory()->count(7)->for($companyB)->create();

    // A first, so A is the entry that exists when B asks.
    expect(kpiTotal($this, $userA))->toBe(3)
        ->and(kpiTotal($this, $userB))->toBe(7)
        // And again, now that both are cached, in the other order: a key
        // collision would show up as one of these flipping to the other's
        // number.
        ->and(kpiTotal($this, $userB))->toBe(7)
        ->and(kpiTotal($this, $userA))->toBe(3);

    expect(Cache::has(KpiCache::key($companyA->id)))->toBeTrue()
        ->and(Cache::has(KpiCache::key($companyB->id)))->toBeTrue()
        ->and(KpiCache::key($companyA->id))->not->toBe(KpiCache::key($companyB->id));
});

it('keys the entry by the company id', function () {
    [$company, $user] = tenant();

    kpiTotal($this, $user);

    expect(KpiCache::key($company->id))->toBe('kpis:'.$company->id)
        ->and(Cache::has('kpis:'.$company->id))->toBeTrue();
});

it('answers a second request from the cache', function () {
    [$company, $user] = tenant();
    Feedback::factory()->for($company)->create();

    expect(kpiTotal($this, $user))->toBe(1);

    // Written without an event, so nothing invalidates: the stale answer is
    // the proof that the second request never ran the queries.
    Feedback::factory()->for($company)->create();

    expect(kpiTotal($this, $user))->toBe(1);
});

it('invalidates on FeedbackIngested', function () {
    [$company, $user] = tenant();
    Feedback::factory()->for($company)->create();

    expect(kpiTotal($this, $user))->toBe(1);

    $fresh = Feedback::factory()->for($company)->create();
    FeedbackIngested::dispatch($company->id, $fresh->id);

    expect(Cache::has(KpiCache::key($company->id)))->toBeFalse()
        ->and(kpiTotal($this, $user))->toBe(2);
});

it('invalidates on FeedbackAnalyzed', function () {
    [$company, $user] = tenant();
    $feedback = Feedback::factory()->for($company)->analyzed()->create();

    expect(kpiTotal($this, $user))->toBe(1);

    AiAnalysis::factory()->for($company)->for($feedback)->create([
        'sentiment_label' => 'negative',
        'category' => 'bug',
    ]);

    FeedbackAnalyzed::dispatch($company->id, $feedback->id, 'negative', -0.7, 'bug', 'stub-0.1.0');

    expect(Cache::has(KpiCache::key($company->id)))->toBeFalse();

    $payload = $this->actingAs($user->fresh(), 'sanctum')
        ->getJson('/api/v1/overview/kpis')->assertOk();

    $payload->assertJsonPath('analyzed_count', 1)
        ->assertJsonPath('sentiment_breakdown.negative', 1);
});

it('invalidates only the tenant whose data moved', function () {
    [$companyA, $userA] = tenant();
    [, $userB] = tenant();

    Feedback::factory()->for($companyA)->create();
    kpiTotal($this, $userA);
    kpiTotal($this, $userB);

    $fresh = Feedback::factory()->for($companyA)->create();
    FeedbackIngested::dispatch($companyA->id, $fresh->id);

    expect(Cache::has(KpiCache::key($companyA->id)))->toBeFalse()
        ->and(kpiTotal($this, $userB))->toBe(0);
});

it('recomputes once the TTL has passed', function () {
    [$company, $user] = tenant();
    Feedback::factory()->for($company)->create();

    expect(kpiTotal($this, $user))->toBe(1);

    Feedback::factory()->for($company)->create();

    // Still inside the window: the missed invalidation is still missed.
    $this->travel(59)->seconds();
    expect(kpiTotal($this, $user))->toBe(1);

    // The TTL is the backstop, and it fires.
    $this->travel(2)->seconds();
    expect(kpiTotal($this, $user))->toBe(2);
});

it('computes every time when the TTL is zero', function () {
    // The operational escape hatch of config/overview.php: correctness first,
    // caching second.
    config(['overview.cache.ttl' => 0]);

    [$company, $user] = tenant();
    Feedback::factory()->for($company)->create();

    expect(kpiTotal($this, $user))->toBe(1);

    Feedback::factory()->for($company)->create();

    expect(kpiTotal($this, $user))->toBe(2)
        ->and(Cache::has(KpiCache::key($company->id)))->toBeFalse();
});

it('drops the entry when the tenant is erased', function () {
    [$company, $user] = tenant();
    Feedback::factory()->for($company)->create();

    kpiTotal($this, $user);
    expect(Cache::has(KpiCache::key($company->id)))->toBeTrue();

    $this->actingAs($user->fresh(), 'sanctum')->deleteJson('/api/v1/account')->assertStatus(202);

    // Spec 8: nothing derived from the erased tenant outlives it, including a
    // copy the database cascade cannot reach.
    expect(Cache::has(KpiCache::key($company->id)))->toBeFalse();
});
