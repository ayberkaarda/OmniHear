<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\User;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The soft warning at 80% usage (spec 7.3), on both channels the spec asks for:
 * `mail`, and `database` for the in-app inbox that
 * `GET /api/v1/notifications` serves.
 *
 * App\Events\QuotaThresholdReached is a third, complementary path: it is a live
 * broadcast on the company's private channel, so an open tab reacts at once.
 * It is not a substitute for the stored row — a broadcast nobody is listening
 * to is gone, which is why the `database` channel exists here.
 *
 * Queued, because it is raised from inside AnalyzeFeedbackJob: an SMTP round
 * trip must not sit between a successful analysis and the next one.
 */
class QuotaWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $used,
        public readonly int $limit,
        public readonly string $companyName,
    ) {}

    public static function forCompany(Company $company, int $used, int $limit): self
    {
        return new self($used, $limit, $company->name);
    }

    /**
     * Spec 7.3 asks for the warning by e-mail **and** in-app. `database` is the
     * in-app half: the row it writes is what `GET /api/v1/notifications`
     * serves, so the warning survives a closed tab and an unread mailbox.
     *
     * The channel list is per company, from
     * `companies.notification_preferences` (docs/contracts/settings-api.md
     * section 4). A company that has never touched the setting gets both, which
     * is what the defaults in NotificationPreferences say — the preference is
     * an opt-*out*.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $company = $notifiable instanceof User ? $notifiable->company : null;

        return NotificationPreferences::forCompany($company)
            ->channelsFor(NotificationPreferences::QUOTA_WARNING);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $percent = $this->limit > 0 ? (int) floor($this->used / $this->limit * 100) : 100;

        return (new MailMessage)
            ->subject(__('quota.warning.subject', ['percent' => $percent]))
            ->greeting(__('quota.warning.greeting', ['name' => $notifiable->name]))
            ->line(__('quota.warning.line', [
                'company' => $this->companyName,
                'used' => $this->used,
                'limit' => $this->limit,
            ]))
            ->line(__('quota.warning.line_remaining'))
            ->action(__('quota.warning.action'), rtrim((string) config('app.frontend_url'), '/').'/billing')
            ->salutation(__('quota.warning.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'used' => $this->used,
            'limit' => $this->limit,
            'company' => $this->companyName,
        ];
    }
}
