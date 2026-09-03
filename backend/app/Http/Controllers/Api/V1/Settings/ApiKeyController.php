<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Settings\StoreApiKeyRequest;
use App\Http\Resources\Api\V1\ApiKeyResource;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\TokenAbility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API keys (docs/contracts/settings-api.md section 3).
 *
 * # The boundary this file exists to hold
 *
 * An API key and a device session are the same kind of row in the same table.
 * Without a separator, GET /auth/tokens and this endpoint list each other's
 * credentials and either screen's revoke button kills the other's. They are
 * told apart by ability - api here, session there - and
 * App\Support\Auth\TokenAbility owns that decision, including the rule that a
 * legacy wildcard token counts as a session.
 *
 * # The tenant boundary
 *
 * personal_access_tokens has no company_id, so CompanyScope cannot reach it.
 * Every query below therefore starts from the company's own users, which means
 * another tenant's key is not rejected - it is not in the result set, and
 * findOrFail turns it into a 404 rather than a 403 (invariant I1).
 */
class ApiKeyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/settings/api-keys — any role in the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PersonalAccessToken::class);

        $keys = $this->companyTokens($request)
            ->orderBy('id')
            ->get()
            ->filter(fn (PersonalAccessToken $token): bool => TokenAbility::isApiKey($token))
            ->values();

        return response()->json([
            'data' => $keys
                ->map(fn (PersonalAccessToken $token): array => (new ApiKeyResource($token))->resolve())
                ->all(),
        ]);
    }

    /**
     * POST /api/v1/settings/api-keys -> 201 — owner or admin.
     *
     * The 201 body is the only time the plaintext key is ever available. It is
     * not stored (the column holds a SHA-256), not logged, and no later
     * response can reproduce it — the same rule invariant I5 puts on connector
     * credentials, applied to a secret this endpoint mints itself.
     */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $token = $request->user()->createToken(
            (string) $request->string('name'),
            TokenAbility::api(),
        );

        $this->audit->record(
            AuditAction::ApiKeyCreated,
            actor: $request->user(),
            subject: $token->accessToken,
        );

        return response()->json([
            'api_key' => (new ApiKeyResource($token->accessToken))->resolve(),
            'plain_text_token' => $token->plainTextToken,
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/settings/api-keys/{id} -> 204 — owner or admin.
     *
     * Another company's key is a 404 because it is outside the query. A device
     * session addressed through this route is a 404 too, from ApiKeyPolicy: the
     * two screens must not be able to revoke each other's rows.
     */
    public function destroy(Request $request, string $id): Response
    {
        /** @var PersonalAccessToken $token */
        $token = $this->companyTokens($request)->findOrFail($id);

        Gate::authorize('delete', $token);

        // Recorded before the delete, while there is still an id to name.
        $this->audit->record(
            AuditAction::ApiKeyRevoked,
            actor: $request->user(),
            subject: $token,
        );

        $token->delete();

        return response()->noContent();
    }

    /**
     * Every personal access token held by a user of the caller's company.
     *
     * @return Builder<PersonalAccessToken>
     */
    private function companyTokens(Request $request): Builder
    {
        $userIds = $request->user()->company->users()->pluck('id');

        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds);
    }
}
