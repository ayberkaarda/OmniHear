<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Support\Connectors\ConnectorException;
use App\Support\Connectors\ConnectorFactory;
use App\Support\Connectors\ConnectorFailure;
use App\Support\Connectors\IngestionRunner;
use App\Support\Connectors\IntegrationSyncLock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Pull whatever is new for one integration (spec 6.1).
 *
 * Dispatched every five minutes by the scheduler for active integrations, and
 * on demand from POST /api/v1/integrations/{id}/sync.
 *
 * The lock protocol is deliberately asymmetric: **every dispatch site acquires,
 * the job releases.** Acquiring at dispatch is what makes 409 SYNC_IN_PROGRESS
 * honest — the alternative, acquiring when the worker picks the job up, leaves a
 * window in which a queued job is invisible and the endpoint happily queues a
 * second one.
 *
 * The lock is *not* released on the paths where this job is coming back:
 * throttled, or a transient failure being retried. Releasing there would let the
 * scheduler queue a second run for an integration that already has one pending,
 * which is precisely the pile-up a rate-limited platform must not see. The
 * cache TTL and failed() are the safety nets against a wedged lock.
 */
class FetchFeedbackJob extends TenantAwareJob
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(int $companyId, public readonly int $integrationId)
    {
        parent::__construct($companyId);
    }

    protected function handleForTenant(): void
    {
        $lock = app(IntegrationSyncLock::class);
        $integration = Integration::query()->find($this->integrationId);

        if ($integration === null) {
            // Deleted between dispatch and execution. Nothing to sync, and the
            // lock would otherwise sit until its TTL.
            $lock->release($this->integrationId);

            return;
        }

        $platform = (string) $integration->platform;
        $connectors = app(ConnectorFactory::class);

        if ($this->throttled($connectors, $platform)) {
            return;
        }

        try {
            app(IngestionRunner::class)->run($integration);
        } catch (ConnectorException $e) {
            if ($e->failure() === ConnectorFailure::RateLimited) {
                // The platform, not us. Back off without marking the
                // integration broken: nothing is wrong with its configuration.
                $this->release($connectors->retryAfter($platform));

                return;
            }

            $this->recordFailure($integration, $e);

            if ($e->isTransient()) {
                throw $e;
            }

            $lock->release($this->integrationId);

            return;
        }

        $lock->release($this->integrationId);
    }

    /**
     * Per-platform throttle (spec 6.1). Keyed by platform rather than by
     * integration because the limit belongs to the third party: every tenant
     * syncing the same platform draws from the same budget.
     */
    private function throttled(ConnectorFactory $connectors, string $platform): bool
    {
        $limit = $connectors->rateLimit($platform);
        $key = 'connector:'.$platform;

        if (RateLimiter::tooManyAttempts($key, $limit['max_attempts'])) {
            $this->release(max(1, RateLimiter::availableIn($key)));

            return true;
        }

        RateLimiter::hit($key, $limit['decay_seconds']);

        return false;
    }

    private function recordFailure(Integration $integration, ConnectorException $e): void
    {
        // getSafeMessage() is one of a closed set of fixed sentences — there is
        // no path by which an upstream body, header or credential reaches this
        // column or the log line below (invariant I5).
        $integration->forceFill([
            'status' => 'error',
            'sync_error' => $e->getSafeMessage(),
        ])->save();

        $integrationId = (int) $integration->id;
        $platform = (string) $integration->platform;

        Log::warning('Integration sync failed.', [
            'integration_id' => $integrationId,
            'platform' => $platform,
            'reason' => $e->failure()->value,
        ]);
    }

    /**
     * Terminal state after the retries are exhausted: the DLQ entry is Horizon's
     * business, the user-visible state is ours.
     */
    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->runFor($this->companyId, function (): void {
            app(IntegrationSyncLock::class)->release($this->integrationId);

            Integration::query()->find($this->integrationId)?->forceFill([
                'status' => 'error',
                'sync_error' => 'Sync failed after repeated attempts.',
            ])->save();
        });
    }
}
