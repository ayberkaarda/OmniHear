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

    // Phase W5 — the settings surface (docs/contracts/settings-api.md section 6:
    // "every mutating endpoint writes an audit_logs row").
    case ProfileUpdated = 'profile.updated';
    case ProfileEmailChanged = 'profile.email_changed';
    case PasswordChanged = 'auth.password_changed';

    case TeamInvited = 'team.invited';
    case TeamRoleChanged = 'team.role_changed';
    case TeamMemberRemoved = 'team.member_removed';
    case TeamInvitationAccepted = 'team.invitation_accepted';

    case ApiKeyCreated = 'api_key.created';
    case ApiKeyRevoked = 'api_key.revoked';

    case NotificationPreferencesUpdated = 'settings.notification_preferences_updated';
    case NotificationRead = 'notification.read';

    // W10 — two-factor authentication (docs/contracts/w10-two-factor.md).
    //
    // The failed challenge is the one that earns its place: a burst of them on
    // one account is a password already in someone else's hands, which is the
    // single most useful thing this table can tell a reviewer. Metadata carries
    // no secret and no submitted code — the row says that a challenge failed,
    // never what was tried (invariant I5).
    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorDisabled = 'auth.two_factor_disabled';
    case TwoFactorChallengeFailed = 'auth.two_factor_challenge_failed';
}
