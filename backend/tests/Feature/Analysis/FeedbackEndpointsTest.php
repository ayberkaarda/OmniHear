<?php

use App\Models\AiAnalysis;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\User;

/**
 * GET /api/v1/feedbacks and /api/v1/feedbacks/{id}
 * (docs/contracts/wave2-seams.md section 3).
 */
it('lists only the acting tenant feedback, newest first', function () {
    [$company, $user] = tenant();
    [$other] = tenant();

    $older = Feedback::factory()->for($company)->create(['published_at' => now()->subDays(3)]);
    $newer = Feedback::factory()->for($company)->create(['published_at' => now()->subDay()]);
    Feedback::factory()->for($other)->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);
});

it('never serializes raw_payload', function () {
    [$company, $user] = tenant();
    $feedback = Feedback::factory()->for($company)->create([
        'raw_payload' => ['secret_reviewer_email' => 'someone@example.com'],
    ]);

    $index = $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks');
    $show = $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks/'.$feedback->id);

    // raw_payload is bulk provider data and carries author PII the API has no
    // reason to expose; the resource lists its keys by hand for this reason.
    expect($index->json('data.0'))->not->toHaveKey('raw_payload')
        ->and($show->json('feedback'))->not->toHaveKey('raw_payload')
        ->and($index->getContent())->not->toContain('someone@example.com')
        ->and($show->getContent())->not->toContain('someone@example.com');
});

it('returns the full contract shape for a single feedback', function () {
    [$company, $user] = tenant();
    $integration = Integration::factory()->for($company)->create(['platform' => 'appstore']);
    $feedback = Feedback::factory()->for($company)->for($integration)->analyzed()->create();
    AiAnalysis::factory()->for($company)->for($feedback)->create([
        'sentiment_label' => 'negative',
        'category' => 'bug',
    ]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks/'.$feedback->id)
        ->assertOk()
        ->assertJsonStructure([
            'feedback' => [
                'id', 'integration_id', 'platform', 'external_id', 'author', 'body',
                'source_url', 'published_at', 'analysis_status',
                'analysis' => [
                    'sentiment_score', 'sentiment_label', 'category', 'confidence',
                    'keywords', 'model_version', 'analyzed_at',
                ],
            ],
        ])
        ->assertJsonPath('feedback.platform', 'appstore')
        ->assertJsonPath('feedback.analysis.sentiment_label', 'negative')
        ->assertJsonPath('feedback.analysis.category', 'bug');
});

it('leaves analysis null while the feedback is not analysed', function () {
    [$company, $user] = tenant();
    $feedback = Feedback::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks/'.$feedback->id)
        ->assertOk()
        ->assertJsonPath('feedback.analysis', null)
        ->assertJsonPath('feedback.analysis_status', Feedback::STATUS_PENDING);
});

it('answers 404, not 403, for another tenant feedback', function () {
    [, $user] = tenant();
    [$other] = tenant();
    $foreign = Feedback::factory()->for($other)->create();

    // Invariant I1: a 403 would confirm the row exists.
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks/'.$foreign->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('answers 404 for a feedback that does not exist at all', function () {
    [, $user] = tenant();

    // Same status and same body as the cross-tenant case: the two must be
    // indistinguishable from outside.
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks/9999999')
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/feedbacks')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('filters by sentiment, category, platform, status and integration', function () {
    [$company, $user] = tenant();
    $appstore = Integration::factory()->for($company)->create(['platform' => 'appstore']);
    $zendesk = Integration::factory()->for($company)->create(['platform' => 'zendesk']);

    $bug = Feedback::factory()->for($company)->for($appstore)->analyzed()->create();
    AiAnalysis::factory()->for($company)->for($bug)->create([
        'sentiment_label' => 'negative', 'category' => 'bug',
    ]);

    $praise = Feedback::factory()->for($company)->for($zendesk)->analyzed()->create();
    AiAnalysis::factory()->for($company)->for($praise)->create([
        'sentiment_label' => 'positive', 'category' => 'praise',
    ]);

    $pending = Feedback::factory()->for($company)->for($zendesk)->create();

    $acting = $this->actingAs($user, 'sanctum');

    expect($acting->getJson('/api/v1/feedbacks?sentiment=negative')->json('data.*.id'))->toBe([$bug->id])
        ->and($acting->getJson('/api/v1/feedbacks?category=praise')->json('data.*.id'))->toBe([$praise->id])
        ->and($acting->getJson('/api/v1/feedbacks?platform=appstore')->json('data.*.id'))->toBe([$bug->id])
        ->and($acting->getJson('/api/v1/feedbacks?integration_id='.$appstore->id)->json('data.*.id'))->toBe([$bug->id])
        ->and($acting->getJson('/api/v1/feedbacks?analysis_status=pending_analysis')->json('data.*.id'))->toBe([$pending->id]);
});

it('filters by published date range and by free text', function () {
    [$company, $user] = tenant();

    $old = Feedback::factory()->for($company)->create([
        'published_at' => now()->subDays(10),
        'body' => 'The checkout screen throws an error.',
    ]);
    $recent = Feedback::factory()->for($company)->create([
        'published_at' => now()->subDay(),
        'body' => 'Please add a dark mode.',
    ]);

    $acting = $this->actingAs($user, 'sanctum');

    expect($acting->getJson('/api/v1/feedbacks?from='.now()->subDays(2)->toDateString())->json('data.*.id'))
        ->toBe([$recent->id])
        ->and($acting->getJson('/api/v1/feedbacks?to='.now()->subDays(5)->toDateString())->json('data.*.id'))
        ->toBe([$old->id])
        ->and($acting->getJson('/api/v1/feedbacks?q=dark+mode')->json('data.*.id'))
        ->toBe([$recent->id]);
});

it('treats wildcards in the search needle as literal characters', function () {
    [$company, $user] = tenant();
    Feedback::factory()->for($company)->create(['body' => 'Nothing special here.']);

    // An unescaped '%' would turn the filter into LIKE '%%%' and match
    // everything, which is a filter that silently does not filter.
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks?q=%25')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('rejects an unknown filter value with the validation envelope', function () {
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks?sentiment=furious')
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['code', 'message', 'errors' => ['sentiment']]);
});

it('caps per_page at one hundred', function () {
    [, $user] = tenant();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks?per_page=500')
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('paginates', function () {
    [$company, $user] = tenant();
    Feedback::factory()->count(5)->for($company)->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks?per_page=2&page=3')
        ->assertOk()
        ->assertJsonPath('meta.current_page', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonCount(1, 'data');
});

it('serves every role in the tenant', function (string $role) {
    [$company] = tenant();
    $user = User::factory()->for($company)->state(['role' => $role])->create();
    Feedback::factory()->for($company)->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/feedbacks')->assertOk();
})->with([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_MEMBER]);
