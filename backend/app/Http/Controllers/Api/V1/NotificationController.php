<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app notification inbox (docs/contracts/settings-api.md section 4,
 * spec 7.3).
 *
 * Scoped to the authenticated *user*, not to the company: `notifications` has
 * no company_id (it is on the tenant-scope-guard allowlist for that reason) and
 * every query here starts from the caller's own relation. Another user's row is
 * therefore not rejected, it is not in the result set — which is what makes the
 * cross-user case a 404 rather than a 403 (invariant I1).
 */
class NotificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/notifications — newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $request->user()
            ->notifications()
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (DatabaseNotification $notification): array => (new NotificationResource($notification))->resolve())
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/notifications/{id}/read -> 204
     *
     * Idempotent: markAsRead() leaves an already-read row alone.
     */
    public function read(Request $request, string $id): Response
    {
        /** @var DatabaseNotification $notification */
        $notification = $request->user()->notifications()->findOrFail($id);

        $notification->markAsRead();

        $this->audit->record(
            AuditAction::NotificationRead,
            actor: $request->user(),
        );

        return response()->noContent();
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 25)));
    }
}
