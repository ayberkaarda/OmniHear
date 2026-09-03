<?php

namespace App\Http\Requests\Api\V1\Settings;

use App\Support\Notifications\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /api/v1/settings/notifications.
 *
 * The preference is a property of the *company*, not of the caller, so it is
 * guarded by CompanyPolicy::update (owner or admin) — the same gate every other
 * company-wide setting uses. A member turning the quota warning off would be
 * silencing the owners' mailbox, which is not a personal preference.
 *
 * Rules come from NotificationPreferences itself, so a channel added there
 * becomes writable with no second edit and an unknown key is simply not
 * validated into the stored document.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->user()->company);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return NotificationPreferences::rules();
    }
}
