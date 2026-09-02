<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiAnalysis;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The dashboard aggregate (docs/contracts/wave2-seams.md section 3).
 *
 * Every breakdown is a grouped query, not a fetch-and-count in PHP: on a tenant
 * with a real backlog the difference is one round trip against tens of
 * thousands of rows crossing the wire. All of them run through Eloquent so
 * CompanyScope applies - a raw query builder here would silently aggregate
 * every tenant's data into one dashboard, which is the single worst way
 * invariant I1 can fail, because the numbers still look plausible.
 *
 * Both breakdowns are emitted with every enum key present, zero-filled. A
 * client that has to distinguish "no negative feedback" from "the key is
 * missing" ends up writing that defence in two places; the server settles it
 * once.
 */
class OverviewController extends Controller
{
    /**
     * Days of history in the trend series.
     */
    private const TREND_DAYS = 30;

    /**
     * GET /api/v1/overview/kpis
     */
    public function kpis(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Feedback::class);

        $statusCounts = Feedback::query()
            ->selectRaw('analysis_status, count(*) as aggregate')
            ->groupBy('analysis_status')
            ->pluck('aggregate', 'analysis_status');

        $company = $request->user()->company;

        return response()->json([
            'total_feedbacks' => (int) $statusCounts->sum(),
            'analyzed_count' => (int) ($statusCounts[Feedback::STATUS_ANALYZED] ?? 0),
            'pending_analysis_count' => (int) ($statusCounts[Feedback::STATUS_PENDING] ?? 0),
            'average_sentiment' => round((float) AiAnalysis::query()->avg('sentiment_score'), 4),
            'sentiment_breakdown' => $this->breakdown('sentiment_label', AiAnalysis::SENTIMENT_LABELS),
            'category_breakdown' => $this->breakdown('category', AiAnalysis::CATEGORIES),
            'trend' => $this->trend(),
            'quota' => [
                'limit' => (int) $company->quota_limit,
                'used' => (int) $company->analyzed_feedback_count,
                'remaining' => $company->quotaRemaining(),
            ],
        ]);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function breakdown(string $column, array $keys): array
    {
        $counts = AiAnalysis::query()
            ->selectRaw($column.', count(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column);

        $breakdown = [];

        foreach ($keys as $key) {
            $breakdown[$key] = (int) ($counts[$key] ?? 0);
        }

        return $breakdown;
    }

    /**
     * Daily average sentiment over the trailing window.
     *
     * Days with no analysis are absent rather than zero-filled: a zero average
     * would be plotted as neutral sentiment, which is a different statement
     * from "nothing was analysed".
     *
     * @return list<array{date: string, average_sentiment: float, count: int}>
     */
    private function trend(): array
    {
        return AiAnalysis::query()
            ->selectRaw("to_char(analyzed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') as day")
            ->selectRaw('avg(sentiment_score) as average_sentiment')
            ->selectRaw('count(*) as aggregate')
            ->where('analyzed_at', '>=', now()->subDays(self::TREND_DAYS - 1)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->day,
                'average_sentiment' => round((float) $row->average_sentiment, 4),
                'count' => (int) $row->aggregate,
            ])
            ->all();
    }
}
