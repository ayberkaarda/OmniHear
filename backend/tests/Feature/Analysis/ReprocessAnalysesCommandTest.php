<?php

use App\Jobs\AnalyzeFeedbackJob;
use App\Models\AiAnalysis;
use App\Models\Feedback;
use App\Models\Integration;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * `php artisan analysis:reprocess` — the consumer ADR-0004 promised for
 * `ai_analyses.model_version`.
 *
 * The analyzer is always faked with a single closure or a single URL map. See
 * AiClientHealthTest for why a second Http::fake() call inside one test is
 * never the answer (docs/LESSONS.md, 2026-09-03).
 */
beforeEach(function () {
    Queue::fake();
});

const REPROCESS_CURRENT_VERSION = 'omnihear-lexicon-aaaabbbbcccc';

const REPROCESS_STALE_VERSION = 'omnihear-lexicon-000011112222';

function fakeAnalyzerVersion(string $version = REPROCESS_CURRENT_VERSION): void
{
    Http::fake([
        '*/health' => Http::response([
            'status' => 'ok',
            'service' => 'ai-service',
            'model_version' => $version,
            'sentiment_backend' => 'lexicon',
        ], 200),
    ]);
}

/**
 * One analysed feedback plus its analysis, at the given model version.
 */
function analysisAtVersion($company, string $version): Feedback
{
    $feedback = Feedback::factory()->for($company)->analyzed()->create();

    AiAnalysis::factory()->for($company)->for($feedback)->create([
        'model_version' => $version,
    ]);

    return $feedback;
}

it('queues a reprocess job for every analysis the model has outgrown', function () {
    fakeAnalyzerVersion();

    [$company] = tenant();
    $stale = analysisAtVersion($company, REPROCESS_STALE_VERSION);
    $current = analysisAtVersion($company, REPROCESS_CURRENT_VERSION);

    $this->artisan('analysis:reprocess')->assertExitCode(0);

    Queue::assertPushed(
        AnalyzeFeedbackJob::class,
        fn (AnalyzeFeedbackJob $job) => $job->feedbackId === $stale->id
            && $job->companyId === $company->id
            // The decision this command exists to encode: our model change,
            // our cost.
            && $job->reprocess === true
    );

    Queue::assertNotPushed(
        AnalyzeFeedbackJob::class,
        fn (AnalyzeFeedbackJob $job) => $job->feedbackId === $current->id
    );

    Queue::assertPushed(AnalyzeFeedbackJob::class, 1);
});

it('takes the target version from an explicit option without asking the analyzer', function () {
    Http::fake();

    [$company] = tenant();
    $onCurrent = analysisAtVersion($company, REPROCESS_CURRENT_VERSION);
    analysisAtVersion($company, REPROCESS_STALE_VERSION);

    // Declaring the stale version the target inverts which row is stale, which
    // is only observable if the option really is what selects.
    $this->artisan('analysis:reprocess', ['--model-version' => REPROCESS_STALE_VERSION])
        ->assertExitCode(0);

    Queue::assertPushed(
        AnalyzeFeedbackJob::class,
        fn (AnalyzeFeedbackJob $job) => $job->feedbackId === $onCurrent->id
    );
    Queue::assertPushed(AnalyzeFeedbackJob::class, 1);

    Http::assertNothingSent();
});

it('dispatches nothing when the analyzer cannot be reached', function () {
    Http::fake(['*/health' => Http::response(['code' => 'INTERNAL_ERROR'], 503)]);

    [$company] = tenant();
    analysisAtVersion($company, REPROCESS_STALE_VERSION);

    // Re-queueing the whole table because a probe timed out would be far worse
    // than doing nothing, so this exits non-zero and stops.
    $this->artisan('analysis:reprocess')->assertExitCode(1);

    Queue::assertNothingPushed();
});

it('sweeps across tenants, and narrows to one with --company', function () {
    fakeAnalyzerVersion();

    [$companyA] = tenant();
    [$companyB] = tenant();
    $a = analysisAtVersion($companyA, REPROCESS_STALE_VERSION);
    $b = analysisAtVersion($companyB, REPROCESS_STALE_VERSION);

    // A console command is not a tenant: the sweep runs unscoped and each job
    // carries its own company id back into context.
    expect(app(TenantContext::class)->has())->toBeFalse();

    $this->artisan('analysis:reprocess')->assertExitCode(0);
    Queue::assertPushed(AnalyzeFeedbackJob::class, 2);

    Queue::fake();

    $this->artisan('analysis:reprocess', ['--company' => (string) $companyB->id])->assertExitCode(0);

    Queue::assertPushed(
        AnalyzeFeedbackJob::class,
        fn (AnalyzeFeedbackJob $job) => $job->feedbackId === $b->id
    );
    Queue::assertNotPushed(
        AnalyzeFeedbackJob::class,
        fn (AnalyzeFeedbackJob $job) => $job->feedbackId === $a->id
    );
    Queue::assertPushed(AnalyzeFeedbackJob::class, 1);
});

it('exits quietly when every analysis is already current', function () {
    fakeAnalyzerVersion();

    [$company] = tenant();
    analysisAtVersion($company, REPROCESS_CURRENT_VERSION);

    $this->artisan('analysis:reprocess')
        ->expectsOutputToContain('Stale analyses: 0')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

it('rejects a --company that is not a positive integer', function () {
    fakeAnalyzerVersion();

    $this->artisan('analysis:reprocess', ['--company' => 'not-an-id'])->assertExitCode(1);

    Queue::assertNothingPushed();
});

it('reports the count and changes nothing on a dry run', function () {
    fakeAnalyzerVersion();

    [$company] = tenant();
    $stale = analysisAtVersion($company, REPROCESS_STALE_VERSION);
    analysisAtVersion($company, REPROCESS_STALE_VERSION);

    $this->artisan('analysis:reprocess', ['--dry-run' => true])
        ->expectsOutputToContain('Stale analyses: 2')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Queue::assertNothingPushed();

    // Nothing touched: neither the analysis nor the feedback status moved.
    expect(asTenant($company, fn () => AiAnalysis::query()
        ->where('feedback_id', $stale->id)->value('model_version')))->toBe(REPROCESS_STALE_VERSION)
        ->and($stale->fresh()->analysis_status)->toBe(Feedback::STATUS_ANALYZED);
});

it('walks the table in chunks rather than loading it whole', function () {
    fakeAnalyzerVersion();

    [$company] = tenant();
    $integration = Integration::factory()->for($company)->create();

    // 501 rows: one more than the command's page size, so a second chunkById
    // page is required. Written as two bulk inserts because the point of the
    // test is the number of rows, not the factories.
    //
    // tenant-scope: bypass-ok fixture setup only, every row carries an explicit
    // company_id, and nothing is read back through this builder.
    $now = now();
    $rows = [];
    $count = 501;

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'external_id' => 'bulk-'.$i,
            'body' => 'bulk fixture',
            'raw_payload' => '{}',
            'analysis_status' => Feedback::STATUS_ANALYZED,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    DB::table('feedbacks')->insert($rows);

    // tenant-scope: bypass-ok same fixture setup; the ids just written back.
    $feedbackIds = DB::table('feedbacks')->where('company_id', $company->id)->pluck('id');

    $analyses = $feedbackIds->map(fn ($id) => [
        'feedback_id' => $id,
        'company_id' => $company->id,
        'sentiment_score' => 0.1,
        'sentiment_label' => 'neutral',
        'category' => 'bug',
        'confidence' => 0.9,
        'keywords' => '[]',
        'model_version' => REPROCESS_STALE_VERSION,
        'analyzed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])->all();

    // tenant-scope: bypass-ok same fixture setup, explicit company_id on
    // every row.
    DB::table('ai_analyses')->insert($analyses);

    $this->artisan('analysis:reprocess')
        ->expectsOutputToContain('Stale analyses: '.$count)
        ->assertExitCode(0);

    // Every row on both pages, and none twice.
    Queue::assertPushed(AnalyzeFeedbackJob::class, $count);
});

it('does not read ai_analyses through the tenant scope', function () {
    fakeAnalyzerVersion();

    [$company] = tenant();
    analysisAtVersion($company, REPROCESS_STALE_VERSION);

    // If the sweep queried AiAnalysis with CompanyScope applied it would throw
    // MissingTenantContextException here rather than dispatch anything - which
    // is the failure mode a bypass comment is supposed to be a decision about,
    // not an accident.
    expect(fn () => $this->artisan('analysis:reprocess')->run())->not->toThrow(Throwable::class);

    expect(AiAnalysis::query()->withoutGlobalScope(CompanyScope::class)->count())->toBe(1);
    Queue::assertPushed(AnalyzeFeedbackJob::class, 1);
});
