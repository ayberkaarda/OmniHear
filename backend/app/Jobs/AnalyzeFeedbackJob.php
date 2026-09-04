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
 *
 * # Reprocess mode
 *
 * `$reprocess` is set only by App\Console\Commands\ReprocessAnalysesCommand,
 * which re-runs analyses whose `model_version` no longer matches the analyzer.
 * It turns off exactly two things and nothing else:
 *
 * 1. the `analyzed` early return - the whole point is to redo an analysed row;
 * 2. the quota reservation - **a model change is our cost, not the
 *    customer's.** The company did not ask for the second analysis and must
 *    not pay a second unit for it, so the counter is neither reserved nor
 *    released and spec 7.2's "one unit per successful analysis the customer
 *    requested" stays true.
 *
 * Everything else is identical: same uniqueness key, same broadcast, same
 * retry and backoff policy. One consequence is deliberate and load bearing -
 * a failing reprocess restores the feedback's previous status instead of
 * parking it in `pending_analysis`. A parked row is picked up by the
 * post-upgrade sweep (spec 7.5), which dispatches an ordinary, quota-spending
 * job; a failed reprocess would then bill the customer for our retry while
 * the analysis it already paid for still sits in `ai_analyses`.
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
        public readonly bool $reprocess = false,
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

        if (! $this->reprocess && $feedback->analysis_status === Feedback::STATUS_ANALYZED) {
            return;
        }

        $counter = app(QuotaCounter::class);
        $reservation = null;

        // A reprocess spends no quota: it re-runs *our* model change, so the
        // counter is left exactly where it was. Skipping reserve() is what
        // makes that true, and skipping it here - rather than releasing
        // afterwards - is what keeps it true when the analyzer then fails.
        if (! $this->reprocess) {
            $reservation = $counter->reserve($this->companyId);

            if ($reservation === null) {
                $this->park($feedback);

                return;
            }
        }

        $previousStatus = (string) $feedback->analysis_status;

        $feedback->update(['analysis_status' => Feedback::STATUS_ANALYZING]);

        try {
            $result = app(AiClient::class)->analyze(
                $feedback->body,
                $this->languageHint($feedback),
                $this->correlationId ?? (string) Str::uuid(),
            );
        } catch (Throwable $e) {
            if ($reservation !== null) {
                // The slot was reserved for an analysis that never happened.
                $counter->release($this->companyId);
            }

            // A reprocess goes back where it came from; see the class docblock
            // for why `pending_analysis` would be the wrong resting place for
            // a row that already has a paid-for analysis.
            $feedback->update([
                'analysis_status' => $this->reprocess ? $previousStatus : Feedback::STATUS_PENDING,
            ]);

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

        if ($reservation !== null) {
            $this->warnIfThresholdCrossed($reservation);
        }
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
     * A reprocess is the exception: the status is left alone. The catch in
     * handleForTenant() already put the row back the way it was, and its
     * previous analysis is still in `ai_analyses` - marking that `failed`
     * would report a working analysis as broken and, once a human retried it,
     * charge the customer for our model change.
     *
     * Runs outside handle(), so the tenant context has to be established here
     * as well - the queue calls failed() directly.
     */
    public function failed(?Throwable $e): void
    {
        if (! $this->reprocess) {
            app(TenantContext::class)->runFor($this->companyId, function (): void {
                Feedback::query()
                    ->whereKey($this->feedbackId)
                    ->update(['analysis_status' => Feedback::STATUS_FAILED]);
            });
        }

        Log::error('ai.analysis_dead_lettered', [
            'company_id' => $this->companyId,
            'feedback_id' => $this->feedbackId,
            'attempts' => $this->tries,
            'reprocess' => $this->reprocess,
            'reason' => $e?->getMessage(),
        ]);
    }
}
