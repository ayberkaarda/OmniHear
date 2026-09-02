<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FeedbackResource;
use App\Models\AiAnalysis;
use App\Models\Feedback;
use App\Models\Integration;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The inbox (docs/contracts/wave2-seams.md section 3).
 *
 * Tenant isolation is not implemented here and must not be: CompanyScope
 * constrains every query on Feedback, and route model binding on {feedback}
 * therefore raises ModelNotFoundException - rendered as 404 NOT_FOUND - for
 * another tenant's id. Adding an explicit company_id filter would only hide
 * whether the scope is doing its job.
 */
class FeedbackController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/v1/feedbacks
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Feedback::class);

        $filters = $request->validate([
            'sentiment' => ['sometimes', 'string', Rule::in(AiAnalysis::SENTIMENT_LABELS)],
            'category' => ['sometimes', 'string', Rule::in(AiAnalysis::CATEGORIES)],
            'platform' => ['sometimes', 'string', Rule::in(Integration::PLATFORMS)],
            'integration_id' => ['sometimes', 'integer', 'min:1'],
            'analysis_status' => ['sometimes', 'string', Rule::in(Feedback::STATUSES)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'q' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $paginator = $this->query($filters)->paginate(
            (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE)
        );

        return response()->json([
            'data' => FeedbackResource::collection($paginator->getCollection())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/feedbacks/{feedback}
     */
    public function show(Request $request, Feedback $feedback): JsonResponse
    {
        Gate::authorize('view', $feedback);

        $feedback->load(['integration', 'analysis']);

        return response()->json([
            'feedback' => (new FeedbackResource($feedback))->toArray($request),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        return Feedback::query()
            ->with(['integration', 'analysis'])
            ->when(
                isset($filters['sentiment']),
                fn (Builder $q) => $q->whereHas(
                    'analysis',
                    fn (Builder $a) => $a->where('sentiment_label', $filters['sentiment'])
                )
            )
            ->when(
                isset($filters['category']),
                fn (Builder $q) => $q->whereHas(
                    'analysis',
                    fn (Builder $a) => $a->where('category', $filters['category'])
                )
            )
            ->when(
                isset($filters['platform']),
                fn (Builder $q) => $q->whereHas(
                    'integration',
                    fn (Builder $i) => $i->where('platform', $filters['platform'])
                )
            )
            ->when(
                isset($filters['integration_id']),
                fn (Builder $q) => $q->where('integration_id', $filters['integration_id'])
            )
            ->when(
                isset($filters['analysis_status']),
                fn (Builder $q) => $q->where('analysis_status', $filters['analysis_status'])
            )
            ->when(
                isset($filters['from']),
                fn (Builder $q) => $q->where('published_at', '>=', $filters['from'])
            )
            ->when(
                isset($filters['to']),
                fn (Builder $q) => $q->where('published_at', '<=', $filters['to'])
            )
            ->when(
                isset($filters['q']),
                // The wildcards in the needle are escaped: an unescaped '%' from
                // the client turns a filter into a full-table LIKE '%%'.
                fn (Builder $q) => $q->where(
                    'body',
                    'ilike',
                    '%'.addcslashes((string) $filters['q'], '%_\\').'%'
                )
            )
            // Secondary sort on the primary key: published_at is nullable and
            // not unique, and a pager whose order is ambiguous repeats or skips
            // rows between pages.
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }
}
