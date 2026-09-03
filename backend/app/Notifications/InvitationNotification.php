<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The mail that carries a team invitation to somebody who has no account yet
 * (docs/contracts/settings-api.md section 3a).
 *
 * Sent to an AnonymousNotifiable — `Notification::route('mail', $address)` —
 * because the invitee is not a User and must not become one until they accept.
 *
 * # Not queued, on purpose
 *
 * Every other outbound mail in this application is either queued from inside a
 * job (QuotaWarningNotification) or sent inline from the request that caused it
 * (the verification link at registration). This one is inline, and the reason
 * is stronger than consistency: a queued notification is serialized into Redis,
 * and this object carries the **plaintext** invitation token. Queuing it would
 * put a working key to the tenant in the queue payload, where nothing hides it
 * and where a FLUSHALL is the only thing that removes it. The database stores
 * only the SHA-256 precisely so that the plaintext exists nowhere but in this
 * one message (invariant I5).
 */
class InvitationNotification extends Notification
{
    public function __construct(
        private readonly string $plainToken,
        private readonly string $companyName,
        private readonly string $role,
        private readonly ?string $inviterName,
    ) {}

    public static function for(Invitation $invitation, string $plainToken): self
    {
        return new self(
            plainToken: $plainToken,
            companyName: (string) $invitation->company?->name,
            role: (string) $invitation->role,
            inviterName: $invitation->inviter?->name,
        );
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('invitations.mail.subject', ['company' => $this->companyName]))
            ->greeting(__('invitations.mail.greeting'))
            ->line($this->inviterName === null
                ? __('invitations.mail.line', ['company' => $this->companyName])
                : __('invitations.mail.line_from', ['inviter' => $this->inviterName, 'company' => $this->companyName]))
            ->line(__('invitations.mail.role', ['role' => __('invitations.roles.'.$this->role)]))
            ->action(__('invitations.mail.action'), $this->acceptUrl())
            ->line(__('invitations.mail.expiry', ['days' => (int) config('registration.invitation_ttl_days', 7)]))
            ->line(__('invitations.mail.ignore'))
            ->salutation(__('invitations.mail.salutation'));
    }

    /**
     * The SPA route, not an API endpoint: the recipient needs a page that can
     * ask for a name and a password. The same arrangement as the verification
     * link (App\Support\EmailVerificationLink) and the password reset URL.
     */
    public function acceptUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/auth/accept-invitation?token='.urlencode($this->plainToken);
    }
}
