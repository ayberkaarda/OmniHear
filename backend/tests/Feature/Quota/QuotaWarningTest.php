<?php

use App\Events\QuotaThresholdReached;
use App\Jobs\AnalyzeFeedbackJob;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\QuotaWarningNotification;
use App\Support\Quota\QuotaSnapshot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AiServiceFake;

/**
 * Spec 7.3 - the soft warning at 80% usage.
 *
 * The exactly-once property is structural rather than flag-based: the crossing
 * is detected from the value the atomic UPDATE returned, and only one
 * reservation can ever return that value. See QuotaSnapshot.
 */
beforeEach(function () {
    if (! AiServiceFake::available()) {
        $this->markTestSkipped(AiServiceFake::skipReason());
    }
});

function analyseOnce(int $companyId, int $feedbackId): void
{
    (new AnalyzeFeedbackJob($companyId, $feedbackId))->handle(app(TenantContext::class));
}

it('recognises the crossing increment and only that one', function () {
    $threshold = (float) config('quota.warning_threshold');

    // limit 10, threshold 0.8 -> the 8th analysis is the crossing.
    expect((new QuotaSnapshot(7, 10))->crossedWarningThreshold($threshold))->toBeFalse()
        ->and((new QuotaSnapshot(8, 10))->crossedWarningThreshold($threshold))->toBeTrue()
        ->and((new QuotaSnapshot(9, 10))->crossedWarningThreshold($threshold))->toBeFalse()
        ->and((new QuotaSnapshot(10, 10))->crossedWarningThreshold($threshold))->toBeFalse();
});

it('rounds the threshold up so a small quota still warns before it is spent', function () {
    // limit 3, 0.8 -> ceil(2.4) = 3. Rounding down would put the warning at 2,
    // i.e. below 80%, and a limit of 1 would warn at 0.
    expect((new QuotaSnapshot(2, 3))->crossedWarningThreshold(0.8))->toBeFalse()
        ->and((new QuotaSnapshot(3, 3))->crossedWarningThreshold(0.8))->toBeTrue();
});

it('never warns on a company with no quota at all', function () {
    expect((new QuotaSnapshot(1, 0))->crossedWarningThreshold(0.8))->toBeFalse();
});

it('emails the owner and broadcasts in-app when usage crosses the threshold', function () {
    Notification::fake();
    Event::fake([QuotaThresholdReached::class]);

    [$company, $owner] = tenant();
    $company->forceFill(['quota_limit' => 10, 'analyzed_feedback_count' => 7])->save();
    $feedback = Feedback::factory()->for($company)->create();
    AiServiceFake::fakeSuccess();

    analyseOnce($company->id, $feedback->id);

    Notification::assertSentTo($owner, QuotaWarningNotification::class, function ($notification) {
        return $notification->used === 8 && $notification->limit === 10;
    });

    Event::assertDispatched(QuotaThresholdReached::class, function (QuotaThresholdReached $event) use ($company) {
        $channels = array_map(fn ($channel) => (string) $channel, $event->broadcastOn());

        return $event->companyId === $company->id
            && $event->used === 8
            && $event->broadcastWith()['remaining'] === 2
            && $channels === ['private-company.'.$company->id];
    });
});

it('does not warn again on the analyses after the crossing', function () {
    Notification::fake();

    [$company, $owner] = tenant();
    $company->forceFill(['quota_limit' => 10, 'analyzed_feedback_count' => 7])->save();
    $feedbacks = Feedback::factory()->count(3)->for($company)->create();
    AiServiceFake::fakeSuccess();

    foreach ($feedbacks as $feedback) {
        analyseOnce($company->id, $feedback->id);
    }

    // Three analyses, usage 7 -> 10, one warning. Without the transition check
    // this would be three emails.
    Notification::assertSentToTimes($owner, QuotaWarningNotification::class, 1);
});

it('does not warn below the threshold', function () {
    Notification::fake();
    Event::fake([QuotaThresholdReached::class]);

    [$company, $owner] = tenant();
    $company->forceFill(['quota_limit' => 100, 'analyzed_feedback_count' => 0])->save();
    $feedback = Feedback::factory()->for($company)->create();
    AiServiceFake::fakeSuccess();

    analyseOnce($company->id, $feedback->id);

    Notification::assertNotSentTo($owner, QuotaWarningNotification::class);
    Event::assertNotDispatched(QuotaThresholdReached::class);
});

it('warns owners only, not every member of the tenant', function () {
    Notification::fake();

    [$company, $owner] = tenant();
    $member = User::factory()->for($company)->state(['role' => User::ROLE_MEMBER])->create();
    $company->forceFill(['quota_limit' => 10, 'analyzed_feedback_count' => 7])->save();
    $feedback = Feedback::factory()->for($company)->create();
    AiServiceFake::fakeSuccess();

    analyseOnce($company->id, $feedback->id);

    Notification::assertSentTo($owner, QuotaWarningNotification::class);
    Notification::assertNotSentTo($member, QuotaWarningNotification::class);
});

it('renders the warning email from the translation catalogue', function () {
    [$company, $owner] = tenant();

    $mail = QuotaWarningNotification::forCompany($company, 8, 10)->toMail($owner);

    // CLAUDE.md section 6: no hard-coded user-facing text. A missing key would
    // surface here as the key itself.
    expect($mail->subject)->toContain('80')
        ->and($mail->subject)->not->toContain('quota.warning')
        ->and(implode(' ', $mail->introLines))->not->toContain('quota.warning')
        ->and($mail->actionText)->not->toContain('quota.warning');
});

it('renders the warning email in Turkish when the catalogue is Turkish', function () {
    [$company, $owner] = tenant();

    app()->setLocale('tr');
    $mail = QuotaWarningNotification::forCompany($company, 8, 10)->toMail($owner);

    expect($mail->subject)->toBe(__('quota.warning.subject', ['percent' => 80], 'tr'))
        ->and($mail->subject)->not->toBe(__('quota.warning.subject', ['percent' => 80], 'en'));
});
