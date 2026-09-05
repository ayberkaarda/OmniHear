<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Integration\StoreIntegrationRequest;
use App\Http\Requests\Api\V1\Integration\UpdateIntegrationRequest;
use App\Http\Resources\Api\V1\IntegrationResource;
use App\Jobs\FetchFeedbackJob;
use App\Models\Integration;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Connectors\IntegrationSyncLock;
use App\Support\Http\ApiErrorCode;
use App\Support\Overview\KpiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Integration CRUD plus the manual sync trigger.
 *
 * Contract: docs/contracts/wave2-seams.md section 3.
 *
 * Every read goes through the Eloquent model, so CompanyScope constrains it and
 * another tenant's id resolves to a 404 rather than a 403 (invariant I1). There
 * is no `where company_id` anywhere in this file on purpose — a filter that has
 * to be remembered is a filter that will eventually be forgotten.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationSyncLock $lock,
        private readonly AuditLogger $audit,
        private readonly KpiCache $kpis,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Integration::class);

        $paginator = Integration::query()
            ->withCount('feedbacks')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Integration $integration): array => (new IntegrationResource($integration))->resolve())
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreIntegrationRequest $request): JsonResponse
    {
        $integration = new Integration;

        // company_id is filled from the tenant context by BelongsToCompany and
        // is never read from the request body.
        $integration->fill([
            'platform' => $request->string('platform')->value(),
            'settings' => $request->input('settings') ?? [],
            'credentials' => $request->input('credentials') ?? [],
        ])->save();

        return $this->single($integration, 201);
    }

    public function show(string $integration): JsonResponse
    {
        $model = $this->find($integration);

        Gate::authorize('view', $model);

        return $this->single($model);
    }

    public function update(UpdateIntegrationRequest $request): JsonResponse
    {
        $integration = $request->integration();

        // Only what was actually sent: a PATCH that omits credentials must not
        // wipe them, and one that omits settings must not empty them.
        $integration->fill($request->only(['settings', 'credentials', 'status']))->save();

        return $this->single($integration);
    }

    public function destroy(string $integration): JsonResponse
    {
        $model = $this->find($integration);

        Gate::authorize('delete', $model);

        $companyId = (int) $model->company_id;

        $model->delete();

        // feedbacks.integration_id and, under it, ai_analyses.feedback_id both
        // cascadeOnDelete, so this one delete removes the channel's feedback
        // and analyses too. total_feedbacks, analyzed_count, both breakdowns
        // and the trend all move - the dashboard's main cards - and this is a
        // user-triggered, immediately visible action, so the cache is dropped
        // here rather than left to the TTL. Same pattern as
        // AccountController::destroy.
        $this->kpis->forget($companyId);

        return response()->json(null, 204);
    }

    /**
     * Queue an out-of-band sync.
     *
     * The lock is taken here rather than inside the job: a job that is queued
     * but not yet running is invisible, so acquiring on pickup would let this
     * endpoint answer 202 twice for the same integration and put two runs on
     * the queue. The job releases it.
     */
    public function sync(Request $request, string $integration): JsonResponse
    {
        $integration = $this->find($integration);

        Gate::authorize('sync', $integration);

        if (! $this->lock->acquire((int) $integration->id)) {
            throw new ApiException(ApiErrorCode::SyncInProgress);
        }

        // Audited here rather than in the job: this records the human who asked
        // for an out-of-band sync, which is the part a reviewer cares about.
        // The scheduled runs are machine activity and stay out of the table.
        $this->audit->record(
            AuditAction::IntegrationSyncRequested,
            actor: $request->user(),
            subject: $integration,
        );

        FetchFeedbackJob::dispatch((int) $integration->company_id, (int) $integration->id);

        // Not a lang key: lang/ is owned centrally for this phase, and
        // messages.php has no ingestion entry yet. Flagged in the phase report.
        return response()->json(['message' => 'Sync queued.'], 202);
    }

    /**
     * Route parameters are ids, not bound models, on purpose.
     *
     * SubstituteBindings sits in Laravel's $middlewarePriority list and
     * SetTenantContext does not, so an implicitly bound {integration} would be
     * queried *before* the tenant context exists and CompanyScope would fail
     * closed on every request. Resolving here — after the middleware stack, in
     * the controller — keeps the global scope doing its job, which is what
     * turns another tenant's id into a 404 rather than a 403 (invariant I1).
     */
    private function find(string $id): Integration
    {
        return Integration::query()->findOrFail($id);
    }

    private function single(Integration $integration, int $status = 200): JsonResponse
    {
        $integration->refresh()->loadCount('feedbacks');

        return response()->json(
            ['integration' => (new IntegrationResource($integration))->resolve()],
            $status,
        );
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 25)));
    }
}
