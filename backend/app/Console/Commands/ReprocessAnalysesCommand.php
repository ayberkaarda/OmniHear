<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeFeedbackJob;
use App\Models\AiAnalysis;
use App\Models\Scopes\CompanyScope;
use App\Support\Ai\AiClient;
use App\Support\Ai\AiServiceUnavailableException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Re-run analyses that a model change has made stale (spec 5, ADR-0004).
 *
 *   php artisan analysis:reprocess --dry-run
 *   php artisan analysis:reprocess --company=42
 *   php artisan analysis:reprocess --model-version=omnihear-lexicon-0123456789ab
 *
 * # Why `model_version` is worth storing at all
 *
 * ai-service/app/model_version.py derives the identifier from the pipeline
 * source, the category artifact and the sentiment backend, so it moves
 * whenever anything that shapes an answer moves. That is what makes
 * "every analysis whose model_version differs from the analyzer's" an honest
 * list of rows whose result would change if recomputed today - and this
 * command is the consumer that claim was written for.
 *
 * # The target version comes from the analyzer, not from a constant
 *
 * A constant would be a second place for the truth to live, and it would drift
 * the first time the analyzer image was rebuilt without a backend deploy. With
 * no --model-version the command asks the running service (AiClient::health())
 * and refuses to do anything if it cannot get an answer: re-queueing the whole
 * table because the analyzer was briefly unreachable is a far worse outcome
 * than exiting non-zero.
 *
 * # Quota
 *
 * A reprocess does not consume the customer's quota. The company did not ask
 * for the second analysis - we changed our model - so AnalyzeFeedbackJob is
 * dispatched with `reprocess: true`, which skips both the `analyzed` early
 * return and the quota reservation. See that class for the full reasoning.
 *
 * # Tenancy
 *
 * A console command is not a tenant. It follows routes/console.php: the sweep
 * itself runs unscoped - it is one of the few places that legitimately looks
 * across every company - and each dispatched job carries its own company id
 * back into TenantContext::runFor. --company narrows the sweep to one tenant,
 * which is an operator convenience, not a security boundary; the boundary is
 * still the per-job context.
 */
class ReprocessAnalysesCommand extends Command
{
    protected $signature = 'analysis:reprocess
        {--model-version= : Target version (default: the running analyzer\'s)}
        {--company= : Restrict to one company id}
        {--dry-run : Report what would be re-queued and change nothing}';

    protected $description = 'Re-queue analyses whose model_version differs from the analyzer\'s';

    /**
     * Rows per chunkById page. The table grows without bound, so it is never
     * loaded whole; the number is a page size, not a limit on the run.
     */
    private const CHUNK = 500;

    public function handle(AiClient $ai): int
    {
        $target = $this->targetVersion($ai);

        if ($target === null) {
            return self::FAILURE;
        }

        $companyId = $this->companyOption();

        if ($companyId === false) {
            $this->components->error('--company must be a positive integer.');

            return self::FAILURE;
        }

        $total = $this->query($target, $companyId)->count();

        // Plain lines, not $this->components->info(): the component formatter
        // wraps at the terminal width, which would split these strings at an
        // unpredictable point and make them unassertable from a test.
        $this->line('Target model_version: '.$target);
        $this->line('Scope: '.($companyId === null ? 'all companies' : 'company '.$companyId));
        $this->line('Stale analyses: '.$total);

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing dispatched.');

            return self::SUCCESS;
        }

        if ($total === 0) {
            return self::SUCCESS;
        }

        // One id for the whole run, so every log line the sweep produces -
        // here, in the job, and in the analyzer - can be found together.
        $correlationId = (string) Str::uuid();
        $dispatched = 0;

        $this->query($target, $companyId)->chunkById(
            self::CHUNK,
            function (Collection $analyses) use ($correlationId, &$dispatched): void {
                foreach ($analyses as $analysis) {
                    AnalyzeFeedbackJob::dispatch(
                        (int) $analysis->company_id,
                        (int) $analysis->feedback_id,
                        $correlationId,
                        reprocess: true,
                    );

                    $dispatched++;
                }
            },
        );

        $this->line('Dispatched: '.$dispatched.' reprocess job(s). Quota unaffected.');
        $this->line('Correlation id: '.$correlationId);

        return self::SUCCESS;
    }

    /**
     * The analyses that would answer differently today.
     */
    private function query(string $target, ?int $companyId): Builder
    {
        $query = AiAnalysis::query()
            // tenant-scope: bypass-ok a console sweep has no tenant context, and
            // this is deliberately cross-tenant - the same shape routes/console.php
            // uses for ingestion. Nothing tenant-owned is read or written here:
            // only ids are selected, and every dispatched job re-enters its own
            // company's context through TenantAwareJob.
            ->withoutGlobalScope(CompanyScope::class)
            ->select(['id', 'company_id', 'feedback_id'])
            // `<>` is total here because ai_analyses.model_version is NOT NULL
            // (2026_09_02_000006_create_ai_analyses_table.php). Were it
            // nullable, this predicate would answer NULL - not true - for
            // exactly the rows most in need of reprocessing.
            ->where('model_version', '<>', $target);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query;
    }

    /**
     * @return string|null null when the analyzer could not be asked
     */
    private function targetVersion(AiClient $ai): ?string
    {
        $option = $this->option('model-version');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        try {
            return $ai->health()['model_version'];
        } catch (AiServiceUnavailableException $e) {
            $this->components->error(
                'Could not read the analyzer model_version ['.$e->reason.']. '
                .'Pass --model-version to reprocess against a known version.'
            );

            return null;
        }
    }

    /**
     * @return int|null|false false when the option was given but is not a
     *                        positive integer
     */
    private function companyOption(): int|null|false
    {
        $option = $this->option('company');

        if ($option === null || $option === '') {
            return null;
        }

        if (! is_string($option) || ! ctype_digit($option) || (int) $option < 1) {
            return false;
        }

        return (int) $option;
    }
}
