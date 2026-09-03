<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Settings\UpdateNotificationPreferencesRequest;
use App\Models\Company;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-company notification channel preferences
 * (docs/contracts/settings-api.md section 4).
 *
 * The company is read off the authenticated user and there is no id in the
 * path, so — like /settings/profile and DELETE /account — there is no
 * cross-tenant request to reject in the first place.
 *
 * Both endpoints answer with the *defaults-filled* document rather than with
 * whatever happens to be stored. A partial write must not make the SPA guess
 * what the missing keys mean.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/settings/notifications
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => NotificationPreferences::forCompany($request->user()->company)->toArray(),
        ]);
    }

    /**
     * PATCH /api/v1/settings/notifications
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user()->company;

        // Overlay onto the current document rather than replacing it: a PATCH
        // that carries only quota_warning.mail must not silently reset the
        // in-app channel to its default.
        $merged = array_replace_recursive(
            NotificationPreferences::forCompany($company)->toArray(),
            $request->validated(),
        );

        // Normalised on the way in, so unknown events and unknown channels
        // never reach the column and via() can never be handed a channel
        // Laravel cannot resolve.
        $preferences = NotificationPreferences::fromStored($merged);

        // forceFill: the column is deliberately outside $fillable so that no
        // request body can write an arbitrary document into it.
        $company->forceFill(['notification_preferences' => $preferences->toArray()])->save();

        $this->audit->record(
            AuditAction::NotificationPreferencesUpdated,
            actor: $request->user(),
            subject: $company,
        );

        return response()->json(['preferences' => $preferences->toArray()]);
    }
}
