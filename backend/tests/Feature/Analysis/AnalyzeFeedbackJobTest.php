<?php

use App\Events\FeedbackAnalyzed;
use App\Jobs\AnalyzeFeedbackJob;
use App\Models\AiAnalysis;
use App\Models\Feedback;
use App\Models\Integration;
use App\Support\Ai\AiServiceUnavailableException;
use App\Support\Ai\AnalysisResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiServiceFake;

/**
 * The analysis pipeline itself (spec 6.2 - 6.5).
 *
 * Every analyzer response used here comes from contracts/fixtures/analyze/;
 * see Tests\Support\AiServiceFake for why, and for the mount the fixtures need.
 */
beforeEach(function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }
});

/**
 * Run the job the way a worker would - through handle(), which is what
 * establishes the tenant context.
 */
function runAnalyzeJob(int $companyId, int $feedbackId, ?string $correlationId = null): void
{
    (new AnalyzeFeedbackJob($companyId, $feedbackId, $correlationId))->handle(app(TenantContext::class));
}

it('writes the analysis, flips the status and spends one unit of quota', function () {
    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();

    $body = AiServiceFake::fakeSuccess('single-bug-report');

    runAnalyzeJob($company->id, $feedback->id);

    $analysis = asTenant($company, fn () => AiAnalysis::query()->where('feedback_id', $feedback->id)->first());

    expect($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_ANALYZED)
        ->and($analysis)->not->toBeNull()
        ->and($analysis->sentiment_label)->toBe($body['sentiment_label'])
        ->and($analysis->category)->toBe($body['category'])
        ->and($analysis->model_version)->toBe($body['model_version'])
        ->and($analysis->company_id)->toBe($company->id)
        ->and((int) $company->fresh()->analyzed_feedback_count)->toBe(1);
});

it('sends the tenant correlation id across the service boundary', function () {
    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();
    AiServiceFake::fakeSuccess();

    runAnalyzeJob($company->id, $feedback->id, '11111111-1111-1111-1111-111111111111');

    Http::assertSent(
        fn ($request) => $request->header('X-Correlation-Id')[0] === '11111111-1111-1111-1111-111111111111'
    );
});

it('passes the integration locale as the language hint', function () {
    [$company] = tenant();
    $integration = Integration::factory()->for($company)->create(['settings' => ['locale' => 'tr']]);
    $feedback = Feedback::factory()->for($company)->for($integration)->create();
    AiServiceFake::fakeSuccess('single-tr-complaint');

    runAnalyzeJob($company->id, $feedback->id);

    Http::assertSent(fn ($request) => json_decode($request->body(), true)['language_hint'] === 'tr');
});

it('broadcasts FeedbackAnalyzed on the tenant private channel', function () {
    Event::fake([FeedbackAnalyzed::class]);

    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();
    $body = AiServiceFake::fakeSuccess('single-en-praise');

    runAnalyzeJob($company->id, $feedback->id);

    Event::assertDispatched(FeedbackAnalyzed::class, function (FeedbackAnalyzed $event) use ($company, $feedback, $body) {
        $channels = array_map(fn ($channel) => (string) $channel, $event->broadcastOn());

        return $event->companyId === $company->id
            && $event->feedbackId === $feedback->id
            && $event->sentimentLabel === $body['sentiment_label']
            && $channels === ['private-company.'.$company->id]
            && $event->broadcastAs() === 'feedback.analyzed';
    });
});

it('keeps the feedback body out of the broadcast payload', function () {
    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create(['body' => 'Highly identifying customer text.']);

    $event = FeedbackAnalyzed::fromResult(
        $company->id,
        $feedback->id,
        AnalysisResult::fromResponse(AiServiceFake::successBody()),
    );

    expect(json_encode($event->broadcastWith()))->not->toContain('Highly identifying');
});

it('does not analyse the same feedback twice', function () {
    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->analyzed()->create();
    AiServiceFake::fakeSuccess();

    runAnalyzeJob($company->id, $feedback->id);

    Http::assertNothingSent();
    expect((int) $company->fresh()->analyzed_feedback_count)->toBe(0);
});

it('refuses to queue a second job for a feedback already in flight', function () {
    Queue::fake();

    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();

    AnalyzeFeedbackJob::dispatch($company->id, $feedback->id);
    AnalyzeFeedbackJob::dispatch($company->id, $feedback->id);

    // Spec 3.3: the unique key is the second layer of idempotency, behind the
    // UNIQUE(integration_id, external_id) that stops the row existing twice.
    Queue::assertPushed(AnalyzeFeedbackJob::class, 1);
});

it('does nothing at all when the feedback row is gone', function () {
    [$company] = tenant();
    AiServiceFake::fakeSuccess();

    runAnalyzeJob($company->id, 9_999_999);

    Http::assertNothingSent();
    expect((int) $company->fresh()->analyzed_feedback_count)->toBe(0);
});

it('gives the quota unit back when the analyzer fails', function () {
    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();
    AiServiceFake::fakeFailure('error-invalid-signature');

    expect(fn () => runAnalyzeJob($company->id, $feedback->id))
        ->toThrow(AiServiceUnavailableException::class);

    // Spec 7.2 counts successful analyses. Without the release, five retries of
    // one broken analysis would cost the customer five units.
    expect((int) $company->fresh()->analyzed_feedback_count)->toBe(0)
        ->and($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_PENDING);
});

it('does not spend quota again on a retry', function () {
    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();
    AiServiceFake::fakeFailure('error-invalid-signature');

    foreach (range(1, 5) as $ignored) {
        try {
            runAnalyzeJob($company->id, $feedback->id);
        } catch (AiServiceUnavailableException) {
            // expected on every attempt
        }
    }

    expect((int) $company->fresh()->analyzed_feedback_count)->toBe(0);
});

it('retries with exponential backoff and at most five attempts', function () {
    [$company] = tenant();
    $job = new AnalyzeFeedbackJob($company->id, 1);

    // Spec 3.5. The values come from config/ai.php; nothing here hard-codes a
    // delay, only the shape of the progression and the documented ceiling.
    $backoff = $job->backoff();

    expect($job->tries)->toBe((int) config('ai.retry.max_attempts'))
        ->and($job->tries)->toBe(5)
        ->and($backoff)->toHaveCount(4)
        ->and($backoff[0])->toBe((int) config('ai.retry.base_delay'))
        ->and($backoff[1])->toBe($backoff[0] * 2)
        ->and($backoff[2])->toBe($backoff[0] * 4)
        ->and($backoff[3])->toBe($backoff[0] * 8);
});

it('marks the feedback failed once the job is dead lettered', function () {
    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();

    // What the queue does after the last attempt: failed() runs outside
    // handle(), so it has to re-establish the tenant context itself.
    (new AnalyzeFeedbackJob($company->id, $feedback->id))
        ->failed(new RuntimeException('analyzer down'));

    expect($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_FAILED);
});

it('routes a spent job into the queue failure path', function () {
    Event::fake([JobFailed::class]);

    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();
    AiServiceFake::fakeFailure('error-invalid-signature');

    // The sync connection hands the exception to Job::fail(), the same entry
    // point a worker uses once $tries is exhausted: it invokes the job's
    // failed() hook and raises JobFailed. (Writing the row into `failed_jobs`
    // is the Worker's own step and has no sync-driver equivalent, so it is not
    // claimed here.)
    try {
        AnalyzeFeedbackJob::dispatch($company->id, $feedback->id);
    } catch (AiServiceUnavailableException) {
        // The sync driver re-throws after recording the failure.
    }

    Event::assertDispatched(JobFailed::class);
    expect($feedback->fresh()->analysis_status)->toBe(Feedback::STATUS_FAILED);
});

it('is queued on the dedicated analysis queue', function () {
    Queue::fake();
    [$company] = tenant();

    AnalyzeFeedbackJob::dispatch($company->id, 1);

    Queue::assertPushedOn((string) config('ai.queue'), AnalyzeFeedbackJob::class);
});
