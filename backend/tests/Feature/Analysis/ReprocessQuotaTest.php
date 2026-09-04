<?php

use App\Events\FeedbackAnalyzed;
use App\Jobs\AnalyzeFeedbackJob;
use App\Models\AiAnalysis;
use App\Models\Feedback;
use App\Support\Ai\AiServiceUnavailableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiServiceFake;

/**
 * AnalyzeFeedbackJob in reprocess mode (spec 5, ADR-0004).
 *
 * The decision under test: **a reprocess does not consume the customer's
 * quota.** Re-running our own model change is our cost, not theirs. Every
 * assertion here reads the counter before and after rather than trusting the
 * absence of a call, because the counter is a database column and the only
 * honest question is where it ended up.
 *
 * The analyzer responses come from contracts/fixtures/analyze/ (CLAUDE.md
 * section 2: a shape the fixtures cover may not be proved with inline JSON).
 */
beforeEach(function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }
});

function runReprocessJob(int $companyId, int $feedbackId, bool $reprocess = true): void
{
    (new AnalyzeFeedbackJob($companyId, $feedbackId, null, $reprocess))
        ->handle(app(TenantContext::class));
}

/**
 * An analysed feedback carrying an analysis from an older model.
 */
function analysedFeedback($company, string $modelVersion = 'omnihear-lexicon-oldoldoldold'): Feedback
{
    $feedback = Feedback::factory()->for($company)->analyzed()->create();

    AiAnalysis::factory()->for($company)->for($feedback)->create([
        'model_version' => $modelVersion,
        'sentiment_label' => 'neutral',
        'category' => 'complaint',
    ]);

    return $feedback;
}

it('re-analyses an already analysed feedback and leaves the quota counter alone', function () {
    [$company] = tenant();
    $company->forceFill(['quota_limit' => 200, 'analyzed_feedback_count' => 5])->save();

    $feedback = analysedFeedback($company);
    $body = AiServiceFake::fakeSuccess('single-bug-report');

    runReprocessJob($company->id, $feedback->id);

    $analysis = asTenant($company, fn () => AiAnalysis::query()->where('feedback_id', $feedback->id)->first());

    expect($analysis->model_version)->toBe($body['model_version'])
        ->and($analysis->sentiment_label)->toBe($body['sentiment_label'])
        ->and($analysis->category)->toBe($body['category'])
        ->and($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_ANALYZED)
        // The whole point. Not 6.
        ->and((int) $company->fresh()->analyzed_feedback_count)->toBe(5);

    Http::assertSentCount(1);
});

it('keeps the ordinary job spending exactly one unit', function () {
    // The non-regression half of the flag: everything a normal job does must
    // still happen, or the change bought its new behaviour with the old one.
    [$company] = tenant();
    $company->forceFill(['quota_limit' => 200, 'analyzed_feedback_count' => 5])->save();

    $feedback = Feedback::factory()->for($company)->create();
    AiServiceFake::fakeSuccess();

    runReprocessJob($company->id, $feedback->id, reprocess: false);

    expect((int) $company->fresh()->analyzed_feedback_count)->toBe(6)
        ->and($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_ANALYZED);
});

it('still refuses to re-analyse an analysed feedback when it is not a reprocess', function () {
    [$company] = tenant();
    $company->forceFill(['quota_limit' => 200, 'analyzed_feedback_count' => 5])->save();

    $feedback = analysedFeedback($company);
    AiServiceFake::fakeSuccess();

    runReprocessJob($company->id, $feedback->id, reprocess: false);

    // The `analyzed` early return is untouched for ordinary jobs: no call, no
    // unit spent.
    Http::assertNothingSent();
    expect((int) $company->fresh()->analyzed_feedback_count)->toBe(5);
});

it('runs even when the tenant quota is exhausted', function () {
    [$company] = tenant();
    $company->forceFill(['quota_limit' => 1, 'analyzed_feedback_count' => 1])->save();

    $feedback = analysedFeedback($company);
    $body = AiServiceFake::fakeSuccess('single-en-praise');

    // An ordinary job would park here (spec 7.4). A reprocess never asks for a
    // unit, so an exhausted quota is simply not its business - and a customer
    // at their limit is exactly who must not be billed for our model change.
    runReprocessJob($company->id, $feedback->id);

    $analysis = asTenant($company, fn () => AiAnalysis::query()->where('feedback_id', $feedback->id)->first());

    expect($analysis->model_version)->toBe($body['model_version'])
        ->and($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_ANALYZED)
        ->and((int) $company->fresh()->analyzed_feedback_count)->toBe(1);
});

it('broadcasts the new result like any other analysis', function () {
    Event::fake([FeedbackAnalyzed::class]);

    [$company] = tenant();
    $feedback = analysedFeedback($company);
    $body = AiServiceFake::fakeSuccess('single-en-praise');

    runReprocessJob($company->id, $feedback->id);

    Event::assertDispatched(
        FeedbackAnalyzed::class,
        fn (FeedbackAnalyzed $event) => $event->companyId === $company->id
            && $event->feedbackId === $feedback->id
            && $event->modelVersion === $body['model_version']
    );
});

it('puts the feedback back where it was when a reprocess fails', function () {
    [$company] = tenant();
    $company->forceFill(['quota_limit' => 200, 'analyzed_feedback_count' => 5])->save();

    $feedback = analysedFeedback($company);
    AiServiceFake::fakeFailure();

    expect(fn () => runReprocessJob($company->id, $feedback->id))
        ->toThrow(AiServiceUnavailableException::class);

    // Not `pending_analysis`. A parked row is picked up by the post-upgrade
    // sweep (spec 7.5), which dispatches an ordinary, quota-spending job - so
    // parking a failed reprocess would bill the customer for our retry.
    expect($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_ANALYZED)
        // Nothing was reserved, so nothing is released and the counter cannot
        // drift downwards either.
        ->and((int) $company->fresh()->analyzed_feedback_count)->toBe(5);
});

it('leaves the previous analysis intact when a reprocess dead letters', function () {
    [$company] = tenant();
    $feedback = analysedFeedback($company, 'omnihear-lexicon-oldoldoldold');

    (new AnalyzeFeedbackJob($company->id, $feedback->id, null, true))
        ->failed(new RuntimeException('analyzer gone'));

    $analysis = asTenant($company, fn () => AiAnalysis::query()->where('feedback_id', $feedback->id)->first());

    // The customer paid for that analysis and it is still correct-as-of the
    // old model; marking the row `failed` would report a working dashboard
    // entry as broken.
    expect($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_ANALYZED)
        ->and($analysis->model_version)->toBe('omnihear-lexicon-oldoldoldold');
});

it('still dead letters an ordinary job as failed', function () {
    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();

    (new AnalyzeFeedbackJob($company->id, $feedback->id))->failed(new RuntimeException('analyzer gone'));

    expect($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_FAILED);
});
