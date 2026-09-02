<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Device-based session revocation (spec 8: "oturum token'ları cihaz bazlı iptal
 * edilebilir").
 *
 * `POST /auth/logout` only ever kills the token in the caller's own hand. This
 * is the other half: see every device that holds a session, and end any of
 * them — which is what a user needs after losing a laptop.
 *
 * Every query starts from `$request->user()->tokens()`, so another user's token
 * id is not merely rejected, it is not in the result set. That is what makes
 * the cross-user case a 404 rather than a 403 (invariant I1's rule: a 403
 * confirms the row exists).
 */
class TokenController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/auth/tokens
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->orderBy('id')->get();

        return response()->json([
            'data' => $tokens->map(fn (PersonalAccessToken $token): array => $this->serialize($token))->all(),
        ]);
    }

    /**
     * DELETE /api/v1/auth/tokens/{token}
     *
     * Revoking the token the request is authenticated with is allowed and ends
     * the session — a user cleaning up "all my devices" should not have to
     * discover which row is the one they are sitting on.
     */
    public function destroy(Request $request, string $token): Response
    {
        $user = $request->user();

        /** @var PersonalAccessToken $model */
        $model = $user->tokens()->findOrFail($token);

        // Written before the delete: after it there is no id left to name.
        $this->audit->record(
            AuditAction::TokenRevoked,
            actor: $user,
            subject: $model,
        );

        $model->delete();

        return response()->noContent();
    }

    /**
     * The hash is the credential. It is not merely hidden from this payload —
     * it is never assembled into one (invariant I5).
     *
     * @return array<string, mixed>
     */
    private function serialize(PersonalAccessToken $token): array
    {
        return [
            'id' => (int) $token->getKey(),
            'name' => (string) $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }
}
