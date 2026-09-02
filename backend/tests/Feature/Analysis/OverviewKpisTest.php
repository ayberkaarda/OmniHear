<?php

use App\Models\AiAnalysis;
use App\Models\Feedback;

/**
 * GET /api/v1/overview/kpis (docs/contracts/wave2-seams.md section 3).
 */
it('returns the full contract shape with an empty tenant zero-filled', function () {
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis')
        ->assertOk()
        ->assertJsonStructure([
            'total_feedbacks', 'analyzed_count', 'pending_analysis_count',
            'average_sentiment',
            'sentiment_breakdown' => ['positive', 'neutral', 'negative'],
            'category_breakdown' => ['complaint', 'praise', 'bug', 'feature_request'],
            'trend',
            'quota' => ['limit', 'used', 'remaining'],
        ])
        ->assertJsonPath('total_feedbacks', 0)
        // Compared numerically, not identically. JSON has one number type, and
        // PHP's json_encode drops a zero fraction unless JSON_PRESERVE_ZERO_FRACTION
        // is set — so an identical assertion against 0.0 tests a serializer flag
        // rather than the contract, and both 0 and 0.0 decode to the same value
        // for the client.
        ->assertJsonPath('average_sentiment', fn ($value) => is_numeric($value) && (float) $value === 0.0)
        // Every enum key is present even with no data, so the client never has
        // to distinguish "zero" from "key missing".
        ->assertJsonPath('sentiment_breakdown.negative', 0)
        ->assertJsonPath('category_breakdown.feature_request', 0)
        ->assertJsonPath('trend', []);
});

it('counts feedback by analysis status', function () {
    [$company, $user] = tenant();

    Feedback::factory()->count(3)->for($company)->create();
    $analyzed = Feedback::factory()->count(2)->for($company)->analyzed()->create();

    foreach ($analyzed as $feedback) {
        AiAnalysis::factory()->for($company)->for($feedback)->create();
    }

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis')
        ->assertOk()
        ->assertJsonPath('total_feedbacks', 5)
        ->assertJsonPath('analyzed_count', 2)
        ->assertJsonPath('pending_analysis_count', 3);
});

it('breaks down sentiment and category and averages the score', function () {
    [$company, $user] = tenant();

    $scores = [
        ['sentiment_label' => 'negative', 'category' => 'bug', 'sentiment_score' => -0.8],
        ['sentiment_label' => 'negative', 'category' => 'complaint', 'sentiment_score' => -0.4],
        ['sentiment_label' => 'positive', 'category' => 'praise', 'sentiment_score' => 0.6],
    ];

    foreach ($scores as $attributes) {
        $feedback = Feedback::factory()->for($company)->analyzed()->create();
        AiAnalysis::factory()->for($company)->for($feedback)->create($attributes);
    }

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis');

    $response->assertOk()
        ->assertJsonPath('sentiment_breakdown', ['positive' => 1, 'neutral' => 0, 'negative' => 2])
        ->assertJsonPath('category_breakdown', [
            'complaint' => 1, 'praise' => 1, 'bug' => 1, 'feature_request' => 0,
        ]);

    expect($response->json('average_sentiment'))->toBeGreaterThan(-0.21)
        ->and($response->json('average_sentiment'))->toBeLessThan(-0.19);
});

it('reports the daily trend and omits days with no analysis', function () {
    [$company, $user] = tenant();

    foreach ([1, 1, 3] as $daysAgo) {
        $feedback = Feedback::factory()->for($company)->analyzed()->create();
        AiAnalysis::factory()->for($company)->for($feedback)->create([
            'sentiment_score' => 0.5,
            'analyzed_at' => now()->subDays($daysAgo),
        ]);
    }

    $trend = $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis')->json('trend');

    // A zero-filled gap day would be plotted as neutral sentiment, which says
    // something different from "nothing was analysed".
    expect($trend)->toHaveCount(2)
        ->and($trend[0]['date'])->toBe(now()->subDays(3)->utc()->toDateString())
        ->and($trend[0]['count'])->toBe(1)
        ->and($trend[1]['date'])->toBe(now()->subDays(1)->utc()->toDateString())
        ->and($trend[1]['count'])->toBe(2)
        ->and($trend[1]['average_sentiment'])->toBe(0.5);
});

it('drops analyses older than the trend window', function () {
    [$company, $user] = tenant();

    $feedback = Feedback::factory()->for($company)->analyzed()->create();
    AiAnalysis::factory()->for($company)->for($feedback)->create([
        'analyzed_at' => now()->subDays(45),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis');

    expect($response->json('trend'))->toBe([])
        // Still counted in the totals - only the series is windowed.
        ->and($response->json('analyzed_count'))->toBe(1);
});

it('reports the tenant quota', function () {
    [$company, $user] = tenant();
    $company->forceFill(['quota_limit' => 200, 'analyzed_feedback_count' => 12])->save();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis')
        ->assertOk()
        ->assertJsonPath('quota', ['limit' => 200, 'used' => 12, 'remaining' => 188]);
});

it('never mixes another tenant data into the aggregate', function () {
    [$company, $user] = tenant();
    [$other] = tenant();

    Feedback::factory()->count(4)->for($other)->analyzed()->create()
        ->each(fn ($feedback) => AiAnalysis::factory()->for($other)->for($feedback)->create());
    Feedback::factory()->for($company)->create();

    // Invariant I1 where it is hardest to notice: an unscoped aggregate still
    // returns a plausible-looking number.
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/overview/kpis')
        ->assertOk()
        ->assertJsonPath('total_feedbacks', 1)
        ->assertJsonPath('analyzed_count', 0)
        ->assertJsonPath('sentiment_breakdown.positive', 0)
        ->assertJsonPath('sentiment_breakdown.neutral', 0)
        ->assertJsonPath('sentiment_breakdown.negative', 0)
        ->assertJsonPath('trend', []);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/overview/kpis')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});
