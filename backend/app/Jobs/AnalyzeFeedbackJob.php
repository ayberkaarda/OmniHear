<?php

namespace App\Jobs;

use App\Events\FeedbackAnalyzed;
use App\Events\QuotaThresholdReached;
use App\Models\AiAnalysis;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\QuotaWarningNotification;
use App\Support\Ai\AiClient;
use App\Support\Ai\AnalysisResult;
use App\Support\Quota\QuotaCounter;
use App\Support\Quota\QuotaSnapshot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Analyse one feedback row: quota gate -> analyzer call -> persistence ->
 * broadcast (spec 6.2 through 6.5).
 *
 * # Order of operations, and why
 *
 * The quota unit is reserved *before* the analyzer is called. An exhausted
 * quota therefore costs no inference at all, which is the point of spec 7.4:
 * the pipeline pauses rather than burning capacity it cannot record. If the
 * call then fails, the unit is released again, so five retries of one failing
 * analysis consume one unit at most - spec 7.2 counts *successful* analyses.
 *
 * # What is not a failure
 *
 * Running out of quota is a normal outcome, not an error: the job parks the
 * feedback in `pending_analysis` and finishes green. Throwing here would retry
 * five times, dead-letter, and then the row could never be picked up by the
 * post-upgrade sweep - which selects exactly on `pending_analysis` (spec 7.5).
 *
 * # Idempotency
 *
 * Three layers, in order of strength: the `ShouldBeUnique` key stops a second
 * copy from being queued at all (spec 3.3); the `analyzed` early return stops a
 * re-delivered copy from spending quota; `ai_analyses.feedback_id UNIQUE` plus
 * updateOrCreate makes the write itself idempotent even if both of those are
 * bypassed.
 */
final class AnalyzeFeedbackJob extends TenantAwareJob implements ShouldBeUnique
{
    /**
     * Spec 3.5: at most five attempts, then the dead letter queue.
     */
    public int $tries;

    /**
     * Long enough to cover the client timeout plus the database work, short
     * enough that a wedged worker is reclaimed rather than held forever.
     */
    public int $timeout = 120;

    /**
     * The unique lock is released when the job is processed or deleted; the TTL
     * only matters if the worker dies mid-flight, and an hour is short enough
     * that a crash cannot silently block a feedback for a day.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        int $companyId,
        public readonly int $feedbackId,
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($companyId);

        $this->tries = (int) config('ai.retry.max_attempts');
        $this->onQueue((string) config('ai.queue'));
    }

    /**
     * One in-flight analysis per feedback row (spec 3.3).
     */
    public function uniqueId(): string
    {
        return 'analyze-feedback-'.$this->feedbackId;
    }

    /**
     * Exponential backoff (spec 3.5): base, 2x, 4x, 8x. One delay fewer than
     * `tries`, because the final attempt is not followed by a wait.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $base = max(1, (int) config('ai.retry.base_delay'));
        $attempts = max(1, (int) config('ai.retry.max_attempts'));

        return array_map(
            fn (int $n): int => $base * (2 ** $n),
            range(0, $attempts - 2),
        );
    }

    protected function handleForTenant(): void
    {
        $feedback = Feedback::query()->find($this->feedbackId);

        if ($feedback === null) {
            return;
        }

        if ($feedback->analysis_status === Feedback::STATUS_ANALYZED) {
            return;
        }

        $counter = app(QuotaCounter::class);
        $reservation = $counter->reserve($this->companyId);

        if ($reservation === null) {
            $this->park($feedback);

            return;
        }

        $feedback->update(['analysis_status' => Feedback::STATUS_ANALYZING]);

        try {
            $result = app(AiClient::class)->analyze(
                $feedback->body,
                $this->languageHint($feedback),
                $this->correlationId ?? (string) Str::uuid(),
            );
        } catch (Throwable $e) {
            // The slot was reserved for an analysis that never happened.
            $counter->release($this->companyId);
            $feedback->update(['analysis_status' => Feedback::STATUS_PENDING]);

            throw $e;
        }

        $this->persist($feedback, $result);

        FeedbackAnalyzed::dispatch(
            $this->companyId,
            $feedback->id,
            $result->sentimentLabel,
            $result->sentimentScore,
            $result->category,
            $result->modelVersion,
        );

        $this->warnIfThresholdCrossed($reservation);
    }

    /**
     * Quota exhausted: the feedback waits, it is never dropped (spec 7.4).
     */
    private function park(Feedback $feedback): void
    {
        if ($feedback->analysis_status !== Feedback::STATUS_PENDING) {
            $feedback->update(['analysis_status' => Feedback::STATUS_PENDING]);
        }

        Log::info('quota.analysis_paused', [
            'company_id' => $this->companyId,
            'feedback_id' => $feedback->id,
        ]);
    }

    private function persist(Feedback $feedback, AnalysisResult $result): void
    {
        DB::transaction(function () use ($feedback, $result): void {
            AiAnalysis::query()->updateOrCreate(
                ['feedback_id' => $feedback->id],
                $result->toAttributes() + ['analyzed_at' => now()],
            );

            $feedback->update(['analysis_status' => Feedback::STATUS_ANALYZED]);
        });
    }

    /**
     * Spec 7.3. Fires for the single reservation that crosses the line; see
     * QuotaSnapshot::crossedWarningThreshold().
     */
    private function warnIfThresholdCrossed(QuotaSnapshot $reservation): void
    {
        if (! $reservation->crossedWarningThreshold((float) config('quota.warning_threshold'))) {
            return;
        }

        QuotaThresholdReached::dispatch($this->companyId, $reservation->used, $reservation->limit);

        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $owners = User::query()
            ->where('company_id', $this->companyId)
            ->where('role', User::ROLE_OWNER)
            ->get();

        if ($owners->isNotEmpty()) {
            Notification::send(
                $owners,
                QuotaWarningNotification::forCompany($company, $reservation->used, $reservation->limit),
            );
        }
    }

    /**
     * The connector records the locale it fetched under; the analyzer treats it
     * as a hint and its own detection wins when they disagree (see the
     * single-wrong-language-hint fixture).
     */
    private function languageHint(Feedback $feedback): ?string
    {
        $locale = $feedback->integration?->settings['locale'] ?? null;

        if (! is_string($locale) || $locale === '') {
            return null;
        }

        return substr($locale, 0, 2);
    }

    /**
     * Every attempt is spent: the job is now in `failed_jobs`, which is this
     * application's dead letter queue (spec 3.5).
     *
     * The reserved quota unit was already released by the catch in
     * handleForTenant(), on this and on every earlier attempt.
     *
     * Runs outside handle(), so the tenant context has to be established here
     * as well - the queue calls failed() directly.
     */
    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->runFor($this->companyId, function (): void {
            Feedback::query()
                ->whereKey($this->feedbackId)
                ->update(['analysis_status' => Feedback::STATUS_FAILED]);
        });

        Log::error('ai.analysis_dead_lettered', [
            'company_id' => $this->companyId,
            'feedback_id' => $this->feedbackId,
            'attempts' => $this->tries,
            'reason' => $e?->getMessage(),
        ]);
    }
}
