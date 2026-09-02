<?php

use App\Events\FeedbackIngested;
use App\Jobs\AnalyzeFeedbackJob;
use App\Listeners\QueueFeedbackAnalysis;
use App\Models\Feedback;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * The ingestion -> analysis seam (docs/contracts/wave2-seams.md section 2).
 *
 * These tests drive the event directly and never call into F4's code, which is
 * the whole point of the seam: the two tracks were built in parallel and each
 * side's suite has to be runnable without the other's classes.
 */
it('registers the listener by auto-discovery, with no provider entry', function () {
    // If discovery ever stops working - a renamed directory, withEvents(false)
    // in bootstrap/app.php - analysis silently stops happening and every other
    // test in this file still passes, because they dispatch the job themselves.
    expect(Event::getListeners(FeedbackIngested::class))->not->toBeEmpty();

    $registered = array_map(
        fn ($listener) => is_array($listener) ? (string) $listener[0] : (string) $listener,
        Event::getRawListeners()[FeedbackIngested::class] ?? [],
    );

    expect(implode('|', $registered))->toContain(QueueFeedbackAnalysis::class);
});

it('queues an analysis when ingestion announces a new feedback', function () {
    Queue::fake();

    [$company] = tenant();
    $feedback = Feedback::factory()->for($company)->create();

    FeedbackIngested::dispatch($company->id, $feedback->id);

    Queue::assertPushed(
        AnalyzeFeedbackJob::class,
        fn (AnalyzeFeedbackJob $job) => $job->companyId === $company->id
            && $job->feedbackId === $feedback->id
    );
});

it('carries the tenant on the job rather than inferring it', function () {
    Queue::fake();

    [$company] = tenant();
    [$other] = tenant();
    $feedback = Feedback::factory()->for($other)->create();

    // The event names the tenant; nothing in the listener reads an ambient
    // context, so a queue worker cannot inherit the previous job's tenant.
    (new QueueFeedbackAnalysis)->handle(new FeedbackIngested($other->id, $feedback->id));

    Queue::assertPushed(
        AnalyzeFeedbackJob::class,
        fn (AnalyzeFeedbackJob $job) => $job->companyId === $other->id
            && $job->companyId !== $company->id
    );
});
