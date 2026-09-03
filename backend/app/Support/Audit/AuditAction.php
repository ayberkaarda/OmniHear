<?php

namespace App\Support\Audit;

/**
 * The closed set of auditable actions (spec 5, `audit_logs.action`).
 *
 * An enum rather than free strings because the column is what a compliance
 * reviewer greps: two spellings of the same event ("login" and "auth.login")
 * make the table unusable, and nothing in the database would catch it.
 *
 * Values are `domain.event`, lower snake case, and never carry an id — the
 * subject columns exist for that.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case LoggedOut = 'auth.logout';
    case TokenRevoked = 'auth.token_revoked';

    case IntegrationCreated = 'integration.created';
    case IntegrationUpdated = 'integration.updated';
    case IntegrationDeleted = 'integration.deleted';
    case IntegrationSyncRequested = 'integration.sync_requested';

    case CheckoutStarted = 'billing.checkout_started';
    case SubscriptionActivated = 'billing.subscription_activated';

    case AccountErased = 'account.erased';

    // Wave 5 — the settings surface (docs/contracts/settings-api.md section 6:
    // "every mutating endpoint writes an audit_logs row").
    case ProfileUpdated = 'profile.updated';
    case ProfileEmailChanged = 'profile.email_changed';
    case PasswordChanged = 'auth.password_changed';

    case TeamInvited = 'team.invited';
    case TeamRoleChanged = 'team.role_changed';
    case TeamMemberRemoved = 'team.member_removed';

    case ApiKeyCreated = 'api_key.created';
    case ApiKeyRevoked = 'api_key.revoked';

    case NotificationPreferencesUpdated = 'settings.notification_preferences_updated';
    case NotificationRead = 'notification.read';
}
